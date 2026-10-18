<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony;

use Odiseo\AiConversationalAgentBundle\Config\AgentConfigProviderInterface;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProviderInterface;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;

/**
 * The provider of a role (the turn or the memory) picked on each call from the settings in
 * force, so a platform saved at runtime takes over on the next turn without a deploy.
 */
final class RoutedProvider implements ModelProviderInterface
{
    public const TURN = 'turn';

    public const MEMORY = 'memory';

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly AgentConfigProviderInterface $config,
        private readonly string $role = self::TURN,
    ) {
    }

    public function capabilities(string $model): ProviderCapabilities
    {
        return $this->provider()->capabilities($model);
    }

    public function stream(TurnRequest $request): iterable
    {
        return $this->provider()->stream($request);
    }

    public function complete(TurnRequest $request): ProviderResponse
    {
        return $this->provider()->complete($request);
    }

    private function provider(): ModelProviderInterface
    {
        $config = $this->config->agentConfig();

        return $this->registry->get(self::MEMORY === $this->role ? $config->memoryPlatform : $config->platform);
    }
}
