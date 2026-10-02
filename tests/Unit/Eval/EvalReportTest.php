<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Eval;

use Odiseo\AiConversationalAgentBundle\Eval\EvalCase;
use Odiseo\AiConversationalAgentBundle\Eval\EvalReport;
use Odiseo\AiConversationalAgentBundle\Eval\EvalResult;
use Odiseo\AiConversationalAgentBundle\Eval\TurnRecording;
use PHPUnit\Framework\TestCase;

final class EvalReportTest extends TestCase
{
    public function testACasePassesWhenEnoughOfItsTrialsPass(): void
    {
        $case = new EvalCase('flaky', ['hi'], []);
        $report = new EvalReport(['flaky' => [new EvalResult($case), new EvalResult($case, ['missed']), new EvalResult($case)]], 2);

        self::assertSame(EvalReport::PASSED, $report->outcome('flaky'));
        self::assertSame(2, $report->passedTrials('flaky'));
    }

    public function testACaseThatFellShortWithAnUngradedTrialIsAnError(): void
    {
        $case = new EvalCase('outage', ['hi'], []);
        $report = new EvalReport(['outage' => [new EvalResult($case, ['missed']), new EvalResult($case, error: 'timeout')]], 2);

        self::assertSame(EvalReport::ERROR, $report->outcome('outage'));
    }

    public function testTheSummaryAddsUpCostRoundsAndCache(): void
    {
        $recording = new TurnRecording();
        $recording->costUsd = 0.02;
        $recording->rounds = 3;
        $recording->turnMs = [1000, 3000];
        $recording->usage = ['input_tokens' => 100, 'cache_creation_input_tokens' => 100, 'cache_read_input_tokens' => 800];
        $recording->verdict = ['verdict' => 'PASS', 'cost_usd' => 0.01];
        $case = new EvalCase('one', ['a', 'b'], [], priority: 'high');

        $summary = (new EvalReport(['one' => [new EvalResult($case, recording: $recording)]]))->summary();

        self::assertSame(1, $summary[EvalReport::PASSED]);
        self::assertSame(['high' => ['passed' => 1, 'total' => 1]], $summary['by_priority']);
        self::assertSame(0.02, $summary['cost_usd']);
        self::assertSame(0.01, $summary['judge_cost_usd']);
        self::assertSame(0.01, $summary['cost_per_turn_usd']);
        self::assertSame(1.5, $summary['rounds_per_turn']);
        self::assertSame(0.8, $summary['cache_hit_rate']);
        self::assertSame(2000, $summary['avg_turn_ms']);
    }
}
