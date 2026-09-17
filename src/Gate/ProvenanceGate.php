<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Gate;

use Odiseo\AiConversationalAgentBundle\Session\Provenance;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;

/**
 * A tool argument or a component field may only name a record some tool returned this session.
 *
 * This is what stops an id the model composed from reaching a write, a card, or the person as
 * a fact. The hint tells the model how to resolve the id properly, because an empty search
 * reads to it as proof the thing does not exist.
 */
final class ProvenanceGate
{
    public const NAME = 'provenance';

    public static function check(TurnState $state, string $id, string $resolveHint): ?ToolOutcome
    {
        if (Provenance::hasSeen($state, $id)) {
            return null;
        }

        return ToolOutcome::held(self::NAME, self::message($id, $resolveHint));
    }

    public static function message(string $id, string $resolveHint): string
    {
        return \sprintf(
            'id %s was not returned by any tool in this session. Resolve it first: %s, then use an id from those results.',
            $id,
            $resolveHint,
        );
    }
}
