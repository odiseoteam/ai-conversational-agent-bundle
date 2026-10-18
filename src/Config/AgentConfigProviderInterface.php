<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Config;

/**
 * The settings in force for the current turn: the deployment's, with what the host changes at
 * runtime laid over them. A service reads it when it needs a value, never in its constructor.
 */
interface AgentConfigProviderInterface
{
    public function agentConfig(): AgentConfig;
}
