<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Session;

/**
 * How a record the session saw is looked up again: by id, case-insensitively, because the
 * model sometimes writes an id back in another case. Gates and cards resolve through here so
 * they agree on what "seen" means.
 */
final class Provenance
{
    public static function hasSeen(TurnState $state, string $id): bool
    {
        return null !== self::find($state, $id);
    }

    /** The record behind $id, of $kind when given; null when the session never saw it. */
    public static function find(TurnState $state, string $id, ?string $kind = null): ?SeenRecord
    {
        $record = $state->seen($id);
        if (null === $record) {
            foreach ($state->seenIds() as $seenId) {
                if (0 === strcasecmp($seenId, $id)) {
                    $record = $state->seen($seenId);
                    break;
                }
            }
        }

        if (null === $record || (null !== $kind && $kind !== $record->kind)) {
            return null;
        }

        return $record;
    }
}
