<?php

declare(strict_types=1);

use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle;
use Odiseo\AiConversationalAgentBundle\Provider\Anthropic\AnthropicProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ApiKeyResolverInterface;
use Odiseo\AiConversationalAgentBundle\Provider\Platform\KeyedPlatform;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// The Anthropic adapter, loaded when symfony/ai-anthropic-platform is installed: the platform
// a role names as "anthropic".
return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    // The configured platform, or one with the key the host resolves at runtime (KeyedPlatformPass).
    $services->set('odiseo_ai_conversational_agent.platform.anthropic', KeyedPlatform::class)
        ->args([service('ai.platform.anthropic'), 'anthropic', null, service(ApiKeyResolverInterface::class)]);
    $services->set('odiseo_ai_conversational_agent.provider.anthropic', AnthropicProvider::class)
        ->args([service('odiseo_ai_conversational_agent.platform.anthropic')])
        ->tag(OdiseoAiConversationalAgentBundle::PROVIDER_TAG, ['platform' => 'anthropic']);
};
