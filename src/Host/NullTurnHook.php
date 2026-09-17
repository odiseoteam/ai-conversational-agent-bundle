<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Host;

use Odiseo\AiAgentBundle\Session\SessionContext;

final class NullTurnHook implements TurnHook
{
    public function beforeTurn(SessionContext $session): void
    {
    }
}
