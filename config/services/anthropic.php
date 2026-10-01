<?php

declare(strict_types=1);

use Odiseo\AiConversationalAgentBundle\Provider\Anthropic\AnthropicProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// The Anthropic adapter, loaded when symfony/ai-platform is installed; without it the host
// aliases ModelProvider itself.
return static function (ContainerConfigurator $container): void {
    $id = 'odiseo_ai_conversational_agent.';

    $container->services()
        ->set($id.'provider.anthropic', AnthropicProvider::class)
        ->args([service('ai.platform.anthropic')])
        ->alias(ModelProvider::class, $id.'provider.anthropic');
};
