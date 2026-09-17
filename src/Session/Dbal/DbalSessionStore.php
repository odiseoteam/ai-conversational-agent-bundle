<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Session\Dbal;

use Doctrine\DBAL\Connection;
use Odiseo\AiAgentBundle\Session\SessionConflictException;
use Odiseo\AiAgentBundle\Session\SessionStore;

/**
 * The default store: two tables, plain SQL, jsonb.
 *
 * The state document is written under a compare-and-set on its version, so two requests racing
 * on one session cannot overwrite each other; the transcript is append-only until a turn
 * compacts it, and then the tail is rewritten. Expiry is a column and a prune command, not a
 * background sweep.
 */
final class DbalSessionStore extends SessionStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $retentionDays = 30,
    ) {
    }

    public function readState(string $sessionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT version, document FROM agent_session_state WHERE session_id = :id AND expires_at > NOW()',
            ['id' => $sessionId],
        );

        if (false === $row) {
            return null;
        }

        $document = json_decode((string) $row['document'], true);

        return [(int) $row['version'], \is_array($document) ? $document : []];
    }

    public function writeState(string $sessionId, array $document, int $version): void
    {
        $json = json_encode($document, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $expiresAt = (new \DateTimeImmutable(\sprintf('+%d days', $this->retentionDays)))->format('Y-m-d H:i:s');

        if (0 === $version) {
            $written = (int) $this->connection->executeStatement(
                'INSERT INTO agent_session_state (session_id, principal_id, version, document, created_at, updated_at, expires_at)
                 VALUES (:id, :principal, 1, CAST(:document AS jsonb), NOW(), NOW(), CAST(:expires AS timestamp))
                 ON CONFLICT (session_id) DO NOTHING',
                [
                    'id' => $sessionId,
                    'principal' => (string) ($document['principal_id'] ?? ''),
                    'document' => $json,
                    'expires' => $expiresAt,
                ],
            );

            if (0 === $written) {
                throw new SessionConflictException($sessionId);
            }

            return;
        }

        $written = (int) $this->connection->executeStatement(
            'UPDATE agent_session_state
             SET document = CAST(:document AS jsonb), version = version + 1, updated_at = NOW(), expires_at = CAST(:expires AS timestamp)
             WHERE session_id = :id AND version = :version',
            [
                'document' => $json,
                'expires' => $expiresAt,
                'id' => $sessionId,
                'version' => $version,
            ],
        );

        if (0 === $written) {
            throw new SessionConflictException($sessionId);
        }
    }

    public function readMessages(string $sessionId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT message FROM agent_session_message WHERE session_id = :id ORDER BY position ASC',
            ['id' => $sessionId],
        );

        $messages = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row, true);
            if (\is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    public function writeMessages(string $sessionId, array $messages, int $start): void
    {
        $this->connection->transactional(static function (Connection $connection) use ($sessionId, $messages, $start): void {
            $connection->executeStatement(
                'DELETE FROM agent_session_message WHERE session_id = :id AND position >= :start',
                ['id' => $sessionId, 'start' => $start],
            );

            foreach ($messages as $offset => $message) {
                $connection->executeStatement(
                    'INSERT INTO agent_session_message (session_id, position, message) VALUES (:id, :position, CAST(:message AS jsonb))',
                    [
                        'id' => $sessionId,
                        'position' => $start + $offset,
                        'message' => json_encode($message, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                    ],
                );
            }
        });
    }

    public function delete(string $sessionId): void
    {
        $this->connection->executeStatement('DELETE FROM agent_session_message WHERE session_id = :id', ['id' => $sessionId]);
        $this->connection->executeStatement('DELETE FROM agent_session_state WHERE session_id = :id', ['id' => $sessionId]);
    }

    public function sessionIdsForPrincipal(string $principalId): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT session_id FROM agent_session_state WHERE principal_id = :principal AND expires_at > NOW() ORDER BY updated_at DESC',
            ['principal' => $principalId],
        ));
    }

    /** Drop what has expired. Called by the prune command, not on the request path. */
    public function prune(): int
    {
        $this->connection->executeStatement(
            'DELETE FROM agent_session_message WHERE session_id IN (SELECT session_id FROM agent_session_state WHERE expires_at <= NOW())',
        );

        return (int) $this->connection->executeStatement('DELETE FROM agent_session_state WHERE expires_at <= NOW()');
    }
}
