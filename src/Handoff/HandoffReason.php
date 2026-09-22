<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

/** Why the assistant handed a conversation over; the model picks one. */
enum HandoffReason: string
{
    /** The person asked for a human. */
    case CustomerAsked = 'customer_asked';
    /** Outside what the assistant covers. */
    case OutOfScope = 'out_of_scope';
    /** Something only the organisation can do: change a record, refund, cancel. */
    case NeedsAction = 'needs_action';
    /** Tried and could not settle it. */
    case Unresolved = 'unresolved';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
