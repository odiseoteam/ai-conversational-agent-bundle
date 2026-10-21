<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Config;

use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Config\ConfigOverridesInterface;
use Odiseo\AiConversationalAgentBundle\Config\RuntimeAgentConfig;
use Odiseo\AiConversationalAgentBundle\Config\ThinkingEffort;
use PHPUnit\Framework\TestCase;

final class RuntimeAgentConfigTest extends TestCase
{
    public function testWithoutChangesTheConfiguredSettingsApply(): void
    {
        $defaults = new AgentConfig(brandName: 'Acme');

        self::assertSame($defaults, (new RuntimeAgentConfig($defaults))->agentConfig());
    }

    public function testTheChangesAreLaidOverTheConfiguredSettings(): void
    {
        $overrides = $this->overrides(['model' => 'gpt-5.6-luna', 'platform' => 'openai', 'thinkingEffort' => 'off', 'sessionBudgetUsd' => 0.2]);
        $config = (new RuntimeAgentConfig(new AgentConfig(brandName: 'Acme'), $overrides))->agentConfig();

        self::assertSame('gpt-5.6-luna', $config->model);
        self::assertSame('openai', $config->platform);
        self::assertNull($config->thinkingEffort);
        self::assertSame(0.2, $config->sessionBudgetUsd);
        self::assertSame('Acme', $config->brandName);
    }

    public function testTheResultIsKeptWhileTheChangesStayTheSame(): void
    {
        $runtime = new RuntimeAgentConfig(new AgentConfig(), $this->overrides(['brandName' => 'Acme']));

        self::assertSame($runtime->agentConfig(), $runtime->agentConfig());
    }

    public function testAnUnknownKeyIsIgnoredAndABadValueFallsBack(): void
    {
        $defaults = new AgentConfig(thinkingEffort: ThinkingEffort::Low);

        self::assertSame($defaults, (new RuntimeAgentConfig($defaults, $this->overrides(['nope' => 1])))->agentConfig());
        self::assertSame($defaults, (new RuntimeAgentConfig($defaults, $this->overrides(['thinkingEffort' => 'loud'])))->agentConfig());
    }

    /** @param array<string, mixed> $changes */
    private function overrides(array $changes): ConfigOverridesInterface
    {
        return new class($changes) implements ConfigOverridesInterface {
            /** @param array<string, mixed> $changes */
            public function __construct(private readonly array $changes)
            {
            }

            public function changes(): array
            {
                return $this->changes;
            }
        };
    }
}
