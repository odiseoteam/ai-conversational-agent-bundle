<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget\Dbal;

use Doctrine\DBAL\Connection;
use Odiseo\AiConversationalAgentBundle\Budget\SpendLedger;

/**
 * One row per charged model call, so a day's spend and a session's spend are both a sum and
 * the detail is there when a bill has to be explained.
 */
final class DbalSpendLedger implements SpendLedger
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(string $sessionId, \DateTimeImmutable $at, float $usd, ?string $clientKey = null): void
    {
        if ($usd <= 0.0) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO agent_spend_ledger (session_id, client_key, spent_on, usd, created_at) VALUES (:id, :client, CAST(:day AS date), :usd, NOW())',
            ['id' => $sessionId, 'client' => $clientKey, 'day' => $at->format('Y-m-d'), 'usd' => $usd],
        );
    }

    public function sessionSpend(string $sessionId): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(usd), 0) FROM agent_spend_ledger WHERE session_id = :id',
            ['id' => $sessionId],
        );
    }

    public function clientSpend(string $clientKey, \DateTimeImmutable $day): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(usd), 0) FROM agent_spend_ledger WHERE client_key = :client AND spent_on = CAST(:day AS date)',
            ['client' => $clientKey, 'day' => $day->format('Y-m-d')],
        );
    }

    public function daySpend(\DateTimeImmutable $day): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(usd), 0) FROM agent_spend_ledger WHERE spent_on = CAST(:day AS date)',
            ['day' => $day->format('Y-m-d')],
        );
    }
}
