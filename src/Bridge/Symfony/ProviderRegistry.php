<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony;

use Odiseo\AiConversationalAgentBundle\Provider\ConfiguredProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The adapters installed, by the platform each one talks to. A role (the turn, the memory, the
 * judge) names its platform in the configuration, possibly through an env var, so the adapter
 * is picked when the role's service is built rather than when the container is compiled.
 */
final class ProviderRegistry
{
    /**
     * @param ServiceLocator<ModelProvider>      $providers
     * @param array<string, array<string, bool>> $capabilities the deployment's overrides, by model
     */
    public function __construct(
        private readonly ServiceLocator $providers,
        private readonly array $capabilities = [],
    ) {
    }

    public function get(string $platform): ModelProvider
    {
        if (!$this->providers->has($platform)) {
            throw new \InvalidArgumentException(\sprintf('No model provider for the platform "%s". Installed: %s.', $platform, implode(', ', array_keys($this->providers->getProvidedServices())) ?: 'none'));
        }

        return new ConfiguredProvider($this->providers->get($platform), $this->capabilities);
    }
}
