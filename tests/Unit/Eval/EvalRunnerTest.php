<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Eval;

use Odiseo\AiConversationalAgentBundle\Eval\EvalCase;
use Odiseo\AiConversationalAgentBundle\Eval\EvalEnvironment;
use Odiseo\AiConversationalAgentBundle\Eval\EvalIsolation;
use Odiseo\AiConversationalAgentBundle\Eval\EvalRunner;
use Odiseo\AiConversationalAgentBundle\Eval\Grader\JudgeGrader;
use Odiseo\AiConversationalAgentBundle\Eval\NullEvalEnvironment;
use Odiseo\AiConversationalAgentBundle\Eval\NullEvalIsolation;
use Odiseo\AiConversationalAgentBundle\Eval\TurnRecording;
use Odiseo\AiConversationalAgentBundle\Host\PrincipalResolver;
use Odiseo\AiConversationalAgentBundle\Provider\AuthenticationException;
use Odiseo\AiConversationalAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\AgentBuilder;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\DirectoryCapability;
use PHPUnit\Framework\TestCase;

/** Scripted cases through the turn runner, graded by code and by a judge. */
final class EvalRunnerTest extends TestCase
{
    public function testACaseThatMeetsItsExpectationsPasses(): void
    {
        $result = $this->runner([
            FakeProvider::toolCall('find_records', ['query' => 'something'], 'tu-1'),
            FakeProvider::text('Found two records.'),
        ])->run(new EvalCase('finds', ['show me what you have'], [
            'calls_tool' => ['find_records'],
            'first_tool' => 'find_records',
            'reply_includes' => ['two records'],
        ]));

        self::assertTrue($result->passed(), implode('; ', $result->failures));
    }

    public function testEveryMissedExpectationIsReported(): void
    {
        $result = $this->runner([FakeProvider::text('No idea.')])->run(new EvalCase('misses', ['show me what you have'], [
            'calls_tool' => ['find_records'],
            'reply_includes' => ['records'],
        ]));

        self::assertFalse($result->passed());
        self::assertCount(2, $result->failures);
    }

    public function testAnExpectedKeyNoGraderOwnsFailsTheCase(): void
    {
        $result = $this->runner([FakeProvider::text('Done.')])->run(new EvalCase('typo', ['hi'], ['cals_tool' => ['find_records']]));

        self::assertSame(['no grader checks the expected key cals_tool'], $result->failures);
    }

    public function testASkippedCaseDoesNotRun(): void
    {
        $result = $this->runner([])->run(new EvalCase('later', ['hi'], [], skip: 'not yet'));

        self::assertTrue($result->skipped);
    }

    public function testTheRecordingAddsUpEveryTurnAndKeepsTheEndState(): void
    {
        $environment = new class implements EvalEnvironment {
            public ?TurnState $state = null;

            public function prepare(EvalCase $case, TurnState $state): void
            {
                $this->state = $state;
            }

            public function snapshot(): array
            {
                return ['cart' => ['lines' => 1]];
            }
        };

        $result = $this->runner([
            FakeProvider::toolCall('find_records', ['query' => 'a'], 'tu-1'),
            FakeProvider::text('First.'),
            FakeProvider::text('[]'),
            FakeProvider::text('Second.'),
        ], environment: $environment)->run(new EvalCase('two-turns', ['one', 'two'], []));

        $recording = $result->recording;
        self::assertNotNull($recording);
        self::assertNotNull($environment->state);
        self::assertSame(3, $recording->rounds);
        self::assertCount(2, $recording->turnMs);
        self::assertSame(30, $recording->usage['input_tokens']);
        self::assertSame(['cart' => ['lines' => 1]], $recording->endState);
        self::assertSame('First.Second.', $recording->reply);
    }

    public function testTheCaseRunsInsideTheIsolation(): void
    {
        $isolation = new class implements EvalIsolation {
            public int $cases = 0;

            public function isolate(\Closure $case): mixed
            {
                ++$this->cases;

                return $case();
            }
        };

        $this->runner([FakeProvider::text('Done.')], isolation: $isolation)->run(new EvalCase('isolated', ['hi'], []));

        self::assertSame(1, $isolation->cases);
    }

    public function testAProviderErrorIsRetriedAndThenReportedAsAnError(): void
    {
        $pauses = [];
        $runner = $this->runner([], pause: static function (int $seconds) use (&$pauses): void {
            $pauses[] = $seconds;
        });

        $result = $runner->run(new EvalCase('outage', ['hi'], ['calls_tool' => ['find_records']]));

        self::assertNotNull($result->error);
        self::assertSame([], $result->failures);
        self::assertSame([1, 2], $pauses);
    }

    public function testARejectedCredentialStopsTheRun(): void
    {
        $provider = new class implements ModelProvider {
            public function capabilities(): ProviderCapabilities
            {
                return new ProviderCapabilities();
            }

            public function stream(TurnRequest $request): iterable
            {
                throw new AuthenticationException('bad key');
            }

            public function complete(TurnRequest $request): ProviderResponse
            {
                throw new AuthenticationException('bad key');
            }
        };
        $builder = new AgentBuilder($provider, extra: [new DirectoryCapability()]);

        $this->expectException(AuthenticationException::class);
        (new EvalRunner($builder->turnRunner(), $builder->sessionStore, $builder->memoryStore, $builder->ledger, self::guest()))
            ->run(new EvalCase('rejected', ['hi'], []));
    }

    public function testTheJudgeGradesTheRubricAndAReplayReusesItsVerdict(): void
    {
        $builder = new AgentBuilder(new FakeProvider([FakeProvider::text('It costs 10.')]), extra: [new DirectoryCapability()]);
        $judge = new JudgeGrader(new FakeProvider([FakeProvider::text('{"verdict": "FAIL", "reason": "the price is 12"}')]), $builder->fence);
        $runner = new EvalRunner($builder->turnRunner(), $builder->sessionStore, $builder->memoryStore, $builder->ledger, self::guest(), judge: $judge);
        $case = new EvalCase('price', ['how much?'], ['rubric' => 'PASS if it says 12. FAIL otherwise.']);

        $live = $runner->run($case);
        self::assertSame(['rubric: the price is 12'], $live->failures);

        $recording = TurnRecording::fromArray($live->recording?->toArray() ?? []);
        self::assertSame(['rubric: the price is 12'], $runner->regrade($case, $recording)->failures);

        $changed = new EvalCase('price', ['how much?'], ['rubric' => 'PASS if it says 10. FAIL otherwise.']);
        self::assertSame(
            ['the rubric or the judge model changed since this recording; run the case live'],
            $runner->regrade($changed, $recording)->judgeFailures,
        );
    }

    public function testAJudgeErrorIsAJudgeFailureNotTheEndOfTheRun(): void
    {
        $builder = new AgentBuilder(new FakeProvider([FakeProvider::text('It costs 10.')]), extra: [new DirectoryCapability()]);
        $judge = new JudgeGrader(new FakeProvider([]), $builder->fence);
        $runner = new EvalRunner($builder->turnRunner(), $builder->sessionStore, $builder->memoryStore, $builder->ledger, self::guest(), judge: $judge);

        $result = $runner->run(new EvalCase('price', ['how much?'], ['rubric' => 'PASS if it says 10. FAIL otherwise.']));

        self::assertSame([], $result->failures);
        self::assertStringStartsWith('the judge failed: ', $result->judgeFailures[0] ?? '');
    }

    /**
     * @param list<ProviderResponse>     $responses
     * @param (\Closure(int): void)|null $pause
     */
    private function runner(
        array $responses,
        ?EvalEnvironment $environment = null,
        ?EvalIsolation $isolation = null,
        ?\Closure $pause = null,
    ): EvalRunner {
        $builder = new AgentBuilder(new FakeProvider($responses), extra: [new DirectoryCapability()]);

        return new EvalRunner(
            $builder->turnRunner(),
            $builder->sessionStore,
            $builder->memoryStore,
            $builder->ledger,
            self::guest(),
            environment: $environment ?? new NullEvalEnvironment(),
            isolation: $isolation ?? new NullEvalIsolation(),
            pause: $pause ?? static function (int $seconds): void {
            },
        );
    }

    private static function guest(): PrincipalResolver
    {
        return new class implements PrincipalResolver {
            public function principalId(): string
            {
                return 'guest';
            }

            public function isGuest(): bool
            {
                return true;
            }
        };
    }
}
