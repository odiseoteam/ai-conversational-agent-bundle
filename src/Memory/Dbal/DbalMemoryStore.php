<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Memory\Dbal;

use Doctrine\DBAL\Connection;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryCategory;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryFact;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryStore;

/**
 * Facts keyed by subject and key, so a later save on the same subject replaces the earlier one
 * instead of piling up near-duplicates. Search is Postgres full text over key and value.
 */
final class DbalMemoryStore implements MemoryStore
{
    public function __construct(
        private readonly Connection $connection,
        /** The text search configuration; 'simple' avoids stemming for a language it does not know. */
        private readonly string $textSearchConfig = 'simple',
    ) {
    }

    public function save(string $subject, MemoryFact $fact): void
    {
        $this->connection->executeStatement(
            'INSERT INTO agent_memory_fact (subject, fact_key, fact_value, category, updated_at, source_session)
             VALUES (:subject, :key, :value, :category, :updated_at, :source)
             ON CONFLICT (subject, fact_key) DO UPDATE
             SET fact_value = EXCLUDED.fact_value,
                 category = EXCLUDED.category,
                 updated_at = EXCLUDED.updated_at,
                 source_session = EXCLUDED.source_session',
            [
                'subject' => $subject,
                'key' => $fact->key,
                'value' => $fact->value,
                'category' => $fact->category->value,
                'updated_at' => ($fact->updatedAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'source' => $fact->sourceSessionTag,
            ],
        );
    }

    public function all(string $subject): array
    {
        return $this->hydrate($this->connection->fetchAllAssociative(
            'SELECT * FROM agent_memory_fact WHERE subject = :subject ORDER BY updated_at DESC',
            ['subject' => $subject],
        ));
    }

    public function search(string $subject, string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ('' === $query) {
            return \array_slice($this->all($subject), 0, $limit);
        }

        return $this->hydrate($this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT * FROM agent_memory_fact
                 WHERE subject = :subject
                   AND to_tsvector(%1$s, fact_key || \' \' || fact_value) @@ plainto_tsquery(%1$s, :query)
                 ORDER BY updated_at DESC
                 LIMIT :limit',
                $this->connection->quote($this->textSearchConfig),
            ),
            ['subject' => $subject, 'query' => $query, 'limit' => $limit],
        ));
    }

    public function forget(string $subject, string $key): bool
    {
        return 0 < (int) $this->connection->executeStatement(
            'DELETE FROM agent_memory_fact WHERE subject = :subject AND fact_key = :key',
            ['subject' => $subject, 'key' => $key],
        );
    }

    public function clear(string $subject): void
    {
        $this->connection->executeStatement('DELETE FROM agent_memory_fact WHERE subject = :subject', ['subject' => $subject]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<MemoryFact>
     */
    private function hydrate(array $rows): array
    {
        return array_map(static fn (array $row): MemoryFact => new MemoryFact(
            (string) $row['fact_key'],
            (string) $row['fact_value'],
            MemoryCategory::tryFrom((string) $row['category']) ?? MemoryCategory::Preference,
            new \DateTimeImmutable((string) $row['updated_at']),
            null === $row['source_session'] ? null : (string) $row['source_session'],
        ), $rows);
    }
}
