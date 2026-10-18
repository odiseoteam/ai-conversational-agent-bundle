<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Session;

/**
 * The session the agent is working for right now: a turn, or the memory extraction after it.
 * Lets a host's runtime settings follow the session where there is no request to read, such
 * as a queue worker.
 */
final class CurrentSession
{
    private ?string $sessionId = null;

    public function enter(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }
}
