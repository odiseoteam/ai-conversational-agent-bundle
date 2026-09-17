<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Host;

use Odiseo\AiConversationalAgentBundle\Session\SessionContext;

/**
 * What the host needs done right before a turn streams, on the request that carries it. A
 * store persists its cart here: the PHP session is written when the response is emitted, so
 * state created mid-stream would not attach to the visitor.
 */
interface TurnHook
{
    public function beforeTurn(SessionContext $session): void;
}
