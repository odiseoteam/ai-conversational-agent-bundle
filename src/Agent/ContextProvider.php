<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Agent;

use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;

/**
 * What the vertical puts in front of the model on every request: the caller's profile, where
 * they are, whatever state the conversation has to account for.
 *
 * It is prompt bytes behind the cache breakpoint, so it should be small and it should only
 * change when the thing it describes changes.
 */
interface ContextProvider
{
    /** @return array<string, mixed> */
    public function contextPayload(SessionContext $session, TurnState $state): array;
}
