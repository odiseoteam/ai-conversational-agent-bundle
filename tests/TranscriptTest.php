<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Tests;

use Odiseo\AiAgentBundle\Agent\Transcript;
use PHPUnit\Framework\TestCase;

final class TranscriptTest extends TestCase
{
    public function testTheLatestExchangeStartsAtWhatTheUserTypedNotAtAToolResult(): void
    {
        $messages = [
            Transcript::userMessage('busco algo para mi sobrina de 7 años'),
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'search_products', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '[]', 'is_error' => false]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Encontré dos opciones.']]],
        ];

        $exchange = Transcript::latestExchange($messages);

        self::assertCount(4, $exchange);
        self::assertSame(
            "user: busco algo para mi sobrina de 7 años\nassistant: Encontré dos opciones.",
            Transcript::text($exchange),
        );
    }

    public function testAnEarlierExchangeIsLeftOut(): void
    {
        $messages = [
            Transcript::userMessage('hola'),
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hola.']]],
            Transcript::userMessage('tengo presupuesto de 30 mil'),
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'present_products', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'Displayed.', 'is_error' => false]]],
        ];

        self::assertSame('user: tengo presupuesto de 30 mil', Transcript::text(Transcript::latestExchange($messages)));
    }
}
