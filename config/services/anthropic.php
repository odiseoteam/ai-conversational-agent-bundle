<?php

declare(strict_types=1);

use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle;
use Odiseo\AiConversationalAgentBundle\Provider\Anthropic\AnthropicProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// The Anthropic adapter, loaded when symfony/ai-anthropic-platform is installed: the platform
// a role names as "anthropic".
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('odiseo_ai_conversational_agent.provider.anthropic', AnthropicProvider::class)
        ->args([service('ai.platform.anthropic')])
        ->tag(OdiseoAiConversationalAgentBundle::PROVIDER_TAG, ['platform' => 'anthropic']);
};
