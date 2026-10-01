<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Config;

use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Config\ThinkingEffort;
use PHPUnit\Framework\TestCase;

/**
 * The thinking effort arrives as a string, often from an env var, so it is checked here and not
 * when the container is built.
 */
final class AgentConfigTest extends TestCase
{
    public function testAStringEffortBecomesItsCase(): void
    {
        self::assertSame(ThinkingEffort::High, (new AgentConfig(thinkingEffort: 'high'))->thinkingEffort);
    }

    public function testOffDisablesThinking(): void
    {
        self::assertNull((new AgentConfig(thinkingEffort: 'off'))->thinkingEffort);
    }

    public function testAnUnknownEffortNamesTheValidOnes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown thinking effort "extreme": use low, medium, high, xhigh, max or off.');

        new AgentConfig(thinkingEffort: 'extreme');
    }
}
