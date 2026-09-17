<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Agent;

use Odiseo\AiAgentBundle\Session\SessionContext;
use Odiseo\AiAgentBundle\Session\TurnState;

final class NullContextProvider implements ContextProvider
{
    public function contextPayload(SessionContext $session, TurnState $state): array
    {
        return [];
    }
}
