<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Session;

/**
 * For tests and single-process runs. Copied both ways, as a real store would: a record's
 * later edits reach the store only through save().
 */
final class InMemorySessionStore extends SessionStore
{
    /** @var array<string, array{0: int, 1: array<string, mixed>}> */
    private array $states = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $transcripts = [];

    public function readState(string $sessionId): ?array
    {
        return $this->states[$sessionId] ?? null;
    }

    public function writeState(string $sessionId, array $document, int $version): void
    {
        $current = $this->states[$sessionId][0] ?? 0;
        if ($current !== $version) {
            throw new SessionConflictException($sessionId);
        }

        $this->states[$sessionId] = [$version + 1, $document];
    }

    public function readMessages(string $sessionId): array
    {
        return $this->transcripts[$sessionId] ?? [];
    }

    public function writeMessages(string $sessionId, array $messages, int $start): void
    {
        $transcript = $this->transcripts[$sessionId] ?? [];
        array_splice($transcript, $start, \count($transcript) - $start, $messages);
        $this->transcripts[$sessionId] = array_values($transcript);
    }

    public function delete(string $sessionId): void
    {
        unset($this->states[$sessionId], $this->transcripts[$sessionId]);
    }

    public function sessionIdsForPrincipal(string $principalId): array
    {
        $ids = [];
        foreach ($this->states as $sessionId => [, $document]) {
            if (($document['principal_id'] ?? null) === $principalId) {
                $ids[] = $sessionId;
            }
        }

        return $ids;
    }
}
