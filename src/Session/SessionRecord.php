<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Session;

/**
 * One session as the host holds it: a small state document and the transcript.
 *
 * $version, $storedState and $storedMessages are what the store held when the record was
 * loaded, so save() writes only the difference. A turn that rewrote earlier messages sets
 * $storedMessages to 0 and the transcript is written whole.
 */
final class SessionRecord
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<string>               $pendingAppEvents
     * @param array<string, mixed>       $storedState
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $principalId,
        public TurnState $state,
        public array $messages = [],
        /** Actions taken outside the conversation since the last reply; the next turn reads them. */
        public array $pendingAppEvents = [],
        public int $version = 0,
        public array $storedState = [],
        public int $storedMessages = 0,
        public bool $ended = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function stateDocument(): array
    {
        return [
            'principal_id' => $this->principalId,
            'state' => $this->state->toArray(),
            'pending_app_events' => array_values($this->pendingAppEvents),
        ];
    }
}
