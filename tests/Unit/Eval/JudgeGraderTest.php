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
    public function testALongTranscriptLosesItsOldestMessagesFirst(): void
    {
        $provider = new FakeProvider([FakeProvider::text('{"verdict": "PASS", "reason": "fine"}')]);
        $judge = new JudgeGrader($provider, new Fence('site_content', 'Quoted.'));
        $recording = new TurnRecording();
        for ($i = 0; $i < 60; ++$i) {
            $recording->transcript[] = ['role' => 'user', 'content' => [['type' => 'tool_result', 'content' => 'result-'.$i.'-'.str_repeat('x', 500)]]];
        }
        $recording->transcript[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'The final answer.']]];
        $recording->payloads[] = ['component' => 'products', 'payload' => ['picks' => [['title' => 'Red Cap', 'price' => 12]]]];

        $verdict = $judge->judge(new EvalCase('long', ['hi'], ['rubric' => 'PASS if it answers. FAIL otherwise.']), $recording);

        self::assertNotNull($verdict);
        self::assertTrue($verdict['truncated']);
        $material = json_encode($provider->requests()[0]->messages, \JSON_UNESCAPED_UNICODE) ?: '';
        self::assertStringContainsString('result-59-', $material);
        self::assertStringNotContainsString('result-0-', $material);
        self::assertStringContainsString('The final answer.', $material);
        self::assertStringContainsString('Red Cap', $material);
    }
}
