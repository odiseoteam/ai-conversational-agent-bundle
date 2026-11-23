<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture;

use Odiseo\AiConversationalAgentBundle\Host\TurnHookInterface;
use Odiseo\AiConversationalAgentBundle\Session\SessionContext;

/** Counts the turns the host's hook ran for. */
final class CountingTurnHook implements TurnHookInterface
{
    public int $calls = 0;

    public function beforeTurn(SessionContext $session): void
    {
        ++$this->calls;
    }
}
