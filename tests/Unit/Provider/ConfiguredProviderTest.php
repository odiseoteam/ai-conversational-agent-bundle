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

    public function testAModelThatWritesBesideItsToolCallsCanSaySo(): void
    {
        $provider = new ConfiguredProvider(
            new FakeProvider([], new ProviderCapabilities(textBesideToolCalls: false)),
            ['gpt-6' => ['text_beside_tool_calls' => true]],
        );

        self::assertTrue($provider->capabilities('gpt-6')->textBesideToolCalls);
        self::assertFalse($provider->capabilities('gpt-5.6-luna')->textBesideToolCalls);
    }

    public function testAnUnknownCapabilityIsRefused(): void
    {
        $this->expectExceptionMessage('Unknown capability "forced_tools"');

        (new ProviderCapabilities())->with(['forced_tools' => false]);
    }
}
