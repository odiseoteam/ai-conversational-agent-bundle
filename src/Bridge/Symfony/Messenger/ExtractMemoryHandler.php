<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Messenger;

use Odiseo\AiConversationalAgentBundle\Agent\AgentLoop;
use Odiseo\AiConversationalAgentBundle\Memory\ExtractMemory;

/**
 * Runs where the message is routed: unrouted, in the process that dispatched it; on a
 * transport, in its worker, where a failure is the transport's to retry.
 */
final readonly class ExtractMemoryHandler
{
    public function __construct(private AgentLoop $agent)
    {
    }

    public function __invoke(ExtractMemory $turn): void
    {
        $this->agent->extractMemory($turn);
    }
}
