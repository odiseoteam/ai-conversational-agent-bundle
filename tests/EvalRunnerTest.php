<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Eval\EvalCase;
use Odiseo\AiConversationalAgentBundle\Eval\EvalRunner;
use Odiseo\AiConversationalAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\AgentBuilder;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\DirectoryCapability;
use PHPUnit\Framework\TestCase;

/** A scripted case graded by code: what the model called and what it said. */
final class EvalRunnerTest extends TestCase
{
    public function testACaseThatMeetsItsExpectationsPasses(): void
    {
        $result = $this->runner(
            FakeProvider::toolCall('find_records', ['query' => 'something'], 'tu-1'),
            FakeProvider::text('Found two records.'),
        )->run(new EvalCase('finds', ['show me what you have'], [
            'calls_tool' => ['find_records'],
            'first_tool' => 'find_records',
            'reply_includes' => ['two records'],
        ]));

        self::assertTrue($result->passed(), implode('; ', $result->failures));
    }

    public function testEveryMissedExpectationIsReported(): void
    {
        $result = $this->runner(FakeProvider::text('No idea.'))->run(new EvalCase('misses', ['show me what you have'], [
            'calls_tool' => ['find_records'],
            'reply_includes' => ['records'],
        ]));

        self::assertFalse($result->passed());
        self::assertCount(2, $result->failures);
    }

    public function testASkippedCaseDoesNotRun(): void
    {
        $result = $this->runner()->run(new EvalCase('later', ['hi'], [], skip: 'not yet'));

        self::assertTrue($result->skipped);
    }

    private function runner(ProviderResponse ...$responses): EvalRunner
    {
        $builder = new AgentBuilder(new FakeProvider(array_values($responses)), extra: [new DirectoryCapability()]);

        return new EvalRunner($builder->loop(), $builder->memoryStore);
    }
}
