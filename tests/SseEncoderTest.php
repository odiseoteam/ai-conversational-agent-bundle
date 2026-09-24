<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Streaming\AgentEvent;
use Odiseo\AiConversationalAgentBundle\Streaming\SseEncoder;
use PHPUnit\Framework\TestCase;

final class SseEncoderTest extends TestCase
{
    public function testAnEventIsOneFrameWithItsDataOnOneLine(): void
    {
        $frame = SseEncoder::encode(AgentEvent::error('budget_exceeded', "Line one\nline two / ñ"));

        self::assertSame(
            "event: error\ndata: {\"code\":\"budget_exceeded\",\"message\":\"Line one\\nline two / ñ\"}\n\n",
            $frame,
        );
    }
}
