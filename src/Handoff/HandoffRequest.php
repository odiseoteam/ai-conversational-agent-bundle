<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

/**
 * What a channel receives when a conversation is handed over. Every string the model wrote
 * (summary, contact) is already sanitized; the excerpt is the last exchanges as plain text.
 */
final readonly class HandoffRequest
{
    /** @param array<string, mixed> $surface where the person is (a page, a channel) */
    public function __construct(
        public string $reference,
        public string $sessionId,
        public string $principalId,
        public bool $guest,
        public HandoffReason $reason,
        public string $summary,
        public ?string $contact,
        public string $excerpt,
        public array $surface,
        public \DateTimeImmutable $requestedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'session_id' => $this->sessionId,
            'principal_id' => $this->principalId,
            'guest' => $this->guest,
            'reason' => $this->reason->value,
            'summary' => $this->summary,
            'contact' => $this->contact,
            'excerpt' => $this->excerpt,
            'surface' => $this->surface,
            'requested_at' => $this->requestedAt->format(\DATE_ATOM),
        ];
    }
}
