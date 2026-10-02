<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Eval;

use Odiseo\AiConversationalAgentBundle\Eval\EvalCase;
use Odiseo\AiConversationalAgentBundle\Eval\Grader\JudgeGrader;
use Odiseo\AiConversationalAgentBundle\Eval\TurnRecording;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Provider\Fake\FakeProvider;
use PHPUnit\Framework\TestCase;

final class JudgeGraderTest extends TestCase
{
    public function testALongTranscriptLosesItsOldestCallsFirst(): void
    {
        $provider = new FakeProvider([FakeProvider::text('{"verdict": "PASS", "reason": "fine"}')]);
        $judge = new JudgeGrader($provider, new Fence('site_content', 'Quoted.'));
        $recording = new TurnRecording();
        for ($i = 0; $i < 60; ++$i) {
            $recording->toolCalls[] = ['tool' => 'find_records', 'input' => ['query' => 'call-'.$i.'-'.str_repeat('x', 500)]];
        }
        $recording->reply = 'The final answer.';

        $verdict = $judge->judge(new EvalCase('long', ['hi'], ['rubric' => 'PASS if it answers. FAIL otherwise.']), $recording);

        self::assertNotNull($verdict);
        self::assertTrue($verdict['truncated']);
        $material = json_encode($provider->requests()[0]->messages, \JSON_UNESCAPED_UNICODE) ?: '';
        self::assertStringContainsString('call-59-', $material);
        self::assertStringNotContainsString('call-0-', $material);
        self::assertStringContainsString('The final answer.', $material);
    }
}
