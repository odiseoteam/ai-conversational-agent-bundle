<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Agent;

use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;

final class NullContextProvider implements ContextProvider
{
    public function contextPayload(SessionContext $session, TurnState $state): array
    {
        return [];
    }
}
