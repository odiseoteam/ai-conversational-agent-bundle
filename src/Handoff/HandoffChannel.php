<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

/**
 * Where a handed-over conversation goes: a mailbox, a ticketing system, a webhook, a live
 * desk. Opening is the only thing the core needs; a channel that keeps the person in the
 * conversation (live) builds on this with its own relay and inbound webhook.
 *
 * Throwing is allowed: the executor reports the tool as unavailable and nothing is recorded.
 */
interface HandoffChannel
{
    /** Stable identifier, e.g. "email"; stored with the record. */
    public function name(): string;

    public function open(HandoffRequest $request): HandoffTicket;
}
