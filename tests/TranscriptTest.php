<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Agent\Transcript;
use PHPUnit\Framework\TestCase;

final class TranscriptTest extends TestCase
{
    public function testTheLatestExchangeStartsAtWhatTheUserTypedNotAtAToolResult(): void
    {
        $messages = [
            Transcript::userMessage('looking for something for my 7-year-old niece'),
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'search_products', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '[]', 'is_error' => false]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Found two options.']]],
        ];

        $exchange = Transcript::latestExchange($messages);

        self::assertCount(4, $exchange);
        self::assertSame(
            "user: looking for something for my 7-year-old niece\nassistant: Found two options.",
            Transcript::text($exchange),
        );
    }

    public function testAnEarlierExchangeIsLeftOut(): void
    {
        $messages = [
            Transcript::userMessage('hi'),
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello.']]],
            Transcript::userMessage('my budget is 30k'),
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'present_products', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'Displayed.', 'is_error' => false]]],
        ];

        self::assertSame('user: my budget is 30k', Transcript::text(Transcript::latestExchange($messages)));
    }
}
