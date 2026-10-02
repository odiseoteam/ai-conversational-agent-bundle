<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Provider;

use Odiseo\AiConversationalAgentBundle\Provider\ConfiguredProvider;
use Odiseo\AiConversationalAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use PHPUnit\Framework\TestCase;

/** The deployment completes what an adapter knows about a model it does not know yet. */
final class ConfiguredProviderTest extends TestCase
{
    public function testAnOverrideAppliesToItsModelOnly(): void
    {
        $provider = new ConfiguredProvider(
            new FakeProvider([], new ProviderCapabilities(forcedToolChoice: true, thinking: true)),
            ['claude-sonnet-6' => ['forced_tool_choice' => false]],
        );

        self::assertFalse($provider->capabilities('claude-sonnet-6')->forcedToolChoice);
        self::assertTrue($provider->capabilities('claude-sonnet-6')->thinking);
        self::assertTrue($provider->capabilities('claude-sonnet-5')->forcedToolChoice);
    }

    public function testAnUnknownCapabilityIsRefused(): void
    {
        $this->expectExceptionMessage('Unknown capability "forced_tools"');

        (new ProviderCapabilities())->with(['forced_tools' => false]);
    }
}
