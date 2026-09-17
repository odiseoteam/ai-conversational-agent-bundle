<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Host;

use Odiseo\AiConversationalAgentBundle\Session\SessionContext;

final class NullTurnHook implements TurnHook
{
    public function beforeTurn(SessionContext $session): void
    {
    }
}
