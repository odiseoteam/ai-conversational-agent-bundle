<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

use Odiseo\AiConversationalAgentBundle\Agent\TurnRunner;
use Odiseo\AiConversationalAgentBundle\Budget\SpendLedger;
use Odiseo\AiConversationalAgentBundle\Eval\Grader\CodeGrader;
use Odiseo\AiConversationalAgentBundle\Eval\Grader\Grader;
use Odiseo\AiConversationalAgentBundle\Eval\Grader\JudgeGrader;
use Odiseo\AiConversationalAgentBundle\Host\PrincipalResolver;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryCategory;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryFact;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryStore;
use Odiseo\AiConversationalAgentBundle\Provider\AuthenticationException;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderException;
use Odiseo\AiConversationalAgentBundle\Session\SeenRecord;
use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\SessionStore;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;

/**
 * Runs a case the way a visitor's turns run: a fresh session through the turn runner, so the
 * host's hook and the memory extraction happen as they do in the chat. The precondition is
 * loaded into the session state, the memory store and the host's environment, and the whole
 * case runs inside the isolation, so it starts from the store and leaves nothing behind.
 *
 * A provider error is retried with a growing pause; once the retries are spent the case is an
 * error, not a failure, because nothing was graded. Any other error in the case (a precondition
 * the store cannot build, a bug) is an error on that case too, so the run goes on and keeps
 * what it already paid for. Only a rejected credential ends it.
 */
final class EvalRunner
{
    /** @var list<Grader> */
    private readonly array $graders;

    /** @var \Closure(int): void */
    private readonly \Closure $pause;

    /**
     * @param iterable<Grader>           $graders
     * @param (\Closure(int): void)|null $pause   seconds between retries; tests pass a no-op
     */
    public function __construct(
        private readonly TurnRunner $turns,
        private readonly SessionStore $sessions,
        private readonly MemoryStore $memory,
        private readonly SpendLedger $ledger,
        private readonly PrincipalResolver $principal,
        iterable $graders = [],
        private readonly ?JudgeGrader $judge = null,
        private readonly EvalEnvironment $environment = new NullEvalEnvironment(),
        private readonly EvalIsolation $isolation = new NullEvalIsolation(),
        private readonly string $timezone = 'UTC',
        private readonly int $retries = 2,
        ?\Closure $pause = null,
    ) {
        $graders = [...$graders];
        $this->graders = [] === $graders ? [new CodeGrader()] : array_values($graders);
        $this->pause = $pause ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function run(EvalCase $case): EvalResult
    {
        if (null !== $case->skip) {
            return new EvalResult($case, skipped: true);
        }

        for ($attempt = 0;; ++$attempt) {
            try {
                $recording = $this->isolation->isolate(fn (): TurnRecording => $this->play($case));
                break;
            } catch (AuthenticationException $rejected) {
                throw $rejected;
            } catch (ProviderException $failed) {
                if ($attempt >= $this->retries) {
                    return new EvalResult($case, error: $failed->getMessage());
                }
                ($this->pause)(2 ** $attempt);
            } catch (\Throwable $broken) {
                return new EvalResult($case, error: $broken->getMessage());
            }
        }

        return $this->grade($case, $recording, true);
    }

    /**
     * Grades a stored recording again, with no model call. A rubric reuses the stored verdict
     * only while the judge model and the rubric are the ones it was scored with.
     */
    public function regrade(EvalCase $case, TurnRecording $recording): EvalResult
    {
        if (null !== $case->skip) {
            return new EvalResult($case, skipped: true);
        }

        return $this->grade($case, $recording, false);
    }

    private function play(EvalCase $case): TurnRecording
    {
        $principal = 'eval-'.substr(hash('sha256', $case->id), 0, 12);
        $this->memory->clear($principal);
        $record = $this->sessions->start($principal);
        $this->environment->prepare($case, $record->state);
        $this->seed($case, $record->state, $principal);
        $session = new SessionContext(
            sessionId: $record->sessionId,
            principalId: $principal,
            timezone: $this->timezone,
            guest: $this->principal->isGuest(),
        );

        $recording = new TurnRecording();
        foreach ($case->turns as $turn) {
            foreach ($this->turns->run($record, $session, $turn) as $event) {
                $recording->record($event);
            }
        }

        $recording->transcript = $record->messages;
        $recording->memory = array_map(static fn (MemoryFact $fact): array => [
            'key' => $fact->key,
            'value' => $fact->value,
            'category' => $fact->category->value,
        ], $this->memory->all($principal));
        $recording->endState = $this->environment->snapshot();
        $recording->costUsd = $this->ledger->sessionSpend($record->sessionId);

        return $recording;
    }

    private function grade(EvalCase $case, TurnRecording $recording, bool $live): EvalResult
    {
        $failures = [];
        $known = ['rubric'];
        foreach ($this->graders as $grader) {
            $known = [...$known, ...$grader->keys()];
        }
        foreach (array_diff(array_keys($case->expected), $known) as $unknown) {
            $failures[] = \sprintf('no grader checks the expected key %s', $unknown);
        }

        foreach ($this->graders as $grader) {
            $failures = [...$failures, ...$grader->grade($case, $recording)];
        }

        $judgeFailures = [];
        $rubric = Scalar::string($case->expected['rubric'] ?? null);
        if ('' !== $rubric) {
            if (null === $this->judge) {
                $judgeFailures[] = 'the case has a rubric and no judge is configured';
            } elseif ($live) {
                try {
                    $recording->verdict = $this->judge->judge($case, $recording);
                    if (null === $recording->verdict) {
                        $judgeFailures[] = 'the judge did not return a verdict';
                    }
                } catch (AuthenticationException $rejected) {
                    throw $rejected;
                } catch (ProviderException $failed) {
                    $judgeFailures[] = 'the judge failed: '.$failed->getMessage();
                }
            } elseif (($recording->verdict['fingerprint'] ?? null) !== $this->judge->fingerprint($rubric)) {
                $judgeFailures[] = 'the rubric or the judge model changed since this recording; run the case live';
            }

            if ([] === $judgeFailures && 'FAIL' === ($recording->verdict['verdict'] ?? null)) {
                $failures[] = 'rubric: '.Scalar::string($recording->verdict['reason'] ?? null);
            }
        }

        return new EvalResult($case, $failures, $judgeFailures, $recording);
    }

    private function seed(EvalCase $case, TurnState $state, string $principal): void
    {
        foreach (\is_array($case->state['seen_records'] ?? null) ? $case->state['seen_records'] : [] as $record) {
            if (\is_string($record)) {
                $state->remember(new SeenRecord($record, 'record'));
            } elseif (\is_array($record)) {
                $state->remember(SeenRecord::fromArray(Scalar::keyed($record)));
            }
        }

        foreach (\is_array($case->state['vertical'] ?? null) ? $case->state['vertical'] : [] as $key => $value) {
            $state->set((string) $key, $value);
        }

        foreach (\is_array($case->state['memory'] ?? null) ? $case->state['memory'] : [] as $fact) {
            if (!\is_array($fact) || !isset($fact['key'], $fact['value'])) {
                continue;
            }
            $this->memory->save($principal, new MemoryFact(
                Scalar::string($fact['key']),
                Scalar::string($fact['value']),
                MemoryCategory::tryFrom(Scalar::string($fact['category'] ?? null)) ?? MemoryCategory::Preference,
                new \DateTimeImmutable(),
                'seeded',
            ));
        }
    }
}
