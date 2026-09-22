<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff\Dbal;

use Doctrine\DBAL\Connection;
use Odiseo\AiConversationalAgentBundle\Handoff\HandoffRecord;
use Odiseo\AiConversationalAgentBundle\Handoff\HandoffStore;

/** The default store: one table, plain SQL, jsonb for the surface. */
final class DbalHandoffStore implements HandoffStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function save(HandoffRecord $record): void
    {
        $this->connection->executeStatement(
            'INSERT INTO agent_handoff (reference, session_id, principal_id, guest, channel, mode, external_ref, url, status, reason, summary, contact, excerpt, surface, created_at, updated_at)
             VALUES (:reference, :session_id, :principal_id, :guest, :channel, :mode, :external_ref, :url, :status, :reason, :summary, :contact, :excerpt, CAST(:surface AS jsonb), :created_at, :updated_at)
             ON CONFLICT (reference) DO UPDATE SET status = EXCLUDED.status, external_ref = EXCLUDED.external_ref, url = EXCLUDED.url, updated_at = EXCLUDED.updated_at',
            [
                'reference' => $record->reference,
                'session_id' => $record->sessionId,
                'principal_id' => $record->principalId,
                'guest' => $record->guest,
                'channel' => $record->channel,
                'mode' => $record->mode,
                'external_ref' => $record->externalRef,
                'url' => $record->url,
                'status' => $record->status,
                'reason' => $record->reason->value,
                'summary' => $record->summary,
                'contact' => $record->contact,
                'excerpt' => $record->excerpt,
                'surface' => json_encode($record->surface, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                'created_at' => $record->createdAt->format('Y-m-d H:i:s'),
                'updated_at' => $record->updatedAt->format('Y-m-d H:i:s'),
            ],
            ['guest' => \Doctrine\DBAL\ParameterType::BOOLEAN],
        );
    }

    public function find(string $reference): ?HandoffRecord
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM agent_handoff WHERE reference = :reference', ['reference' => $reference]);

        return false === $row ? null : $this->hydrate($row);
    }

    /** @return list<HandoffRecord> */
    public function list(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM agent_handoff'.(null === $status ? '' : ' WHERE status = :status').' ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
            array_filter(['status' => $status, 'limit' => $limit, 'offset' => $offset], static fn (mixed $v): bool => null !== $v),
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function count(?string $status = null): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM agent_handoff'.(null === $status ? '' : ' WHERE status = :status'),
            null === $status ? [] : ['status' => $status],
        );
    }

    public function setStatus(string $reference, string $status): void
    {
        $this->connection->executeStatement(
            'UPDATE agent_handoff SET status = :status, updated_at = NOW() WHERE reference = :reference',
            ['status' => $status, 'reference' => $reference],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): HandoffRecord
    {
        $surface = json_decode((string) ($row['surface'] ?? '[]'), true);
        $row['surface'] = \is_array($surface) ? $surface : [];

        return HandoffRecord::fromArray($row);
    }
}
