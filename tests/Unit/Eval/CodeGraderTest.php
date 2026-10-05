<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Eval;

use Odiseo\AiConversationalAgentBundle\Eval\EvalCase;
use Odiseo\AiConversationalAgentBundle\Eval\Grader\CodeGrader;
use Odiseo\AiConversationalAgentBundle\Eval\TurnRecording;
use PHPUnit\Framework\TestCase;

final class CodeGraderTest extends TestCase
{
    public function testATurnThatClosesInTheCardsRoundPasses(): void
    {
        $failures = $this->grade(['present_records', 'present_suggestions']);

        self::assertSame([], $failures);
    }

    public function testARoundOfChipsAfterTheCardFails(): void
    {
        $failures = $this->grade(['present_records'], ['present_suggestions']);

        self::assertSame(['expected the turn to close in the round that calls present_records; the last round called present_suggestions'], $failures);
    }

    public function testAClosingTextAfterTheCardFails(): void
    {
        $failures = $this->grade(['present_records'], []);

        self::assertSame(['expected the turn to close in the round that calls present_records; the last round called nothing'], $failures);
    }

    /**
     * Each argument is one round: the tools its assistant message called.
     *
     * @param list<string> ...$rounds
     *
     * @return list<string>
     */
    private function grade(array ...$rounds): array
    {
        $recording = new TurnRecording();
        $recording->transcript[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'show me what you have']]];
        foreach ($rounds as $i => $tools) {
            $content = [] === $tools ? [['type' => 'text', 'text' => 'Here they are.']] : [];
            $results = [];
            foreach ($tools as $j => $tool) {
                $id = \sprintf('tu-%d-%d', $i, $j);
                $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => []];
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => 'ok'];
            }
            $recording->transcript[] = ['role' => 'assistant', 'content' => $content];
            if ([] !== $results) {
                $recording->transcript[] = ['role' => 'user', 'content' => $results];
            }
        }

        return (new CodeGrader())->grade(new EvalCase('closes', ['show me what you have'], ['closes_on' => 'present_records']), $recording);
    }
}
