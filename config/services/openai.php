<?php

declare(strict_types=1);

use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle;
use Odiseo\AiConversationalAgentBundle\Provider\OpenAi\OpenAiProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// The OpenAI adapter, loaded when symfony/ai-open-ai-platform is installed: the platform a role
// names as "openai".
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('odiseo_ai_conversational_agent.provider.openai', OpenAiProvider::class)
        ->args([service('ai.platform.openai')])
        ->tag(OdiseoAiConversationalAgentBundle::PROVIDER_TAG, ['platform' => 'openai']);
};
