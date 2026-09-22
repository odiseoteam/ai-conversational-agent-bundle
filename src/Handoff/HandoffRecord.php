<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

/** One handed-over conversation as the store keeps it: the request, the ticket, and its status. */
final class HandoffRecord
{
    public const OPEN = 'open';
    public const DONE = 'done';

    /** @param array<string, mixed> $surface */
    public function __construct(
        public readonly string $reference,
        public readonly string $sessionId,
        public readonly string $principalId,
        public readonly bool $guest,
        public readonly string $channel,
        public readonly string $mode,
        public readonly ?string $externalRef,
        public readonly ?string $url,
        public string $status,
        public readonly HandoffReason $reason,
        public readonly string $summary,
        public readonly ?string $contact,
        public readonly string $excerpt,
        public readonly array $surface,
        public readonly \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function open(HandoffRequest $request, HandoffTicket $ticket): self
    {
        return new self(
            reference: $request->reference,
            sessionId: $request->sessionId,
            principalId: $request->principalId,
            guest: $request->guest,
            channel: $ticket->channel,
            mode: $ticket->mode,
            externalRef: $ticket->externalRef,
            url: $ticket->url,
            status: self::OPEN,
            reason: $request->reason,
            summary: $request->summary,
            contact: $request->contact,
            excerpt: $request->excerpt,
            surface: $request->surface,
            createdAt: $request->requestedAt,
            updatedAt: $request->requestedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'session_id' => $this->sessionId,
            'principal_id' => $this->principalId,
            'guest' => $this->guest,
            'channel' => $this->channel,
            'mode' => $this->mode,
            'external_ref' => $this->externalRef,
            'url' => $this->url,
            'status' => $this->status,
            'reason' => $this->reason->value,
            'summary' => $this->summary,
            'contact' => $this->contact,
            'excerpt' => $this->excerpt,
            'surface' => $this->surface,
            'created_at' => $this->createdAt->format(\DATE_ATOM),
            'updated_at' => $this->updatedAt->format(\DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            reference: (string) $row['reference'],
            sessionId: (string) $row['session_id'],
            principalId: (string) $row['principal_id'],
            guest: (bool) $row['guest'],
            channel: (string) $row['channel'],
            mode: (string) ($row['mode'] ?? HandoffTicket::ASYNC),
            externalRef: isset($row['external_ref']) ? (string) $row['external_ref'] : null,
            url: isset($row['url']) ? (string) $row['url'] : null,
            status: (string) $row['status'],
            reason: HandoffReason::tryFrom((string) $row['reason']) ?? HandoffReason::Unresolved,
            summary: (string) $row['summary'],
            contact: isset($row['contact']) ? (string) $row['contact'] : null,
            excerpt: (string) ($row['excerpt'] ?? ''),
            surface: \is_array($row['surface'] ?? null) ? $row['surface'] : [],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
