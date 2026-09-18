<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

/**
 * Who is behind the current turn, beyond the session: the key the client budget is charged
 * to. A session is cheap to open again, so the cap that survives a new one hangs off this.
 */
interface ClientKeyResolver
{
    /** null when there is no client to charge (a console run, a job). */
    public function clientKey(): ?string;
}
