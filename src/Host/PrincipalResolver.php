<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Host;

/**
 * Who is speaking, resolved by the host from its own notion of identity (a signed-in user, a
 * guest token, a messaging-channel id). It is the only thing that enters the session store as
 * a principal; no request and no tool argument ever names one.
 */
interface PrincipalResolver
{
    public function principalId(): string;

    public function isGuest(): bool;
}
