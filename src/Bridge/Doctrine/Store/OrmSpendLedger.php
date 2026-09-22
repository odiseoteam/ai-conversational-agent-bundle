<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store;

use Doctrine\ORM\EntityManagerInterface;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\SpendEntry;
use Odiseo\AiConversationalAgentBundle\Budget\SpendLedger;
use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;

/**
 * One row per charged model call, with the model and the tokens behind the amount, so a
 * day's spend and a session's spend are both a sum and the detail is there when a bill has
 * to be explained.
 */
final class OrmSpendLedger implements SpendLedger
{
    /** @param class-string<SpendEntry> $entryClass */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $entryClass,
    ) {
    }

    public function record(string $sessionId, \DateTimeImmutable $at, float $usd, ?string $clientKey = null, ?string $model = null, ?Usage $usage = null): void
    {
        if ($usd <= 0.0) {
            return;
        }

        $entry = new $this->entryClass();
        $entry->setSessionId($sessionId);
        $entry->setClientKey($clientKey);
        $entry->setSpentOn($at);
        $entry->setUsd($usd);
        $entry->setModel($model);
        if (null !== $usage) {
            $entry->setTokens($usage->inputTokens, $usage->outputTokens, $usage->cacheCreationTokens, $usage->cacheReadTokens);
        }

        $this->em->persist($entry);
        $this->em->flush();
    }

    public function sessionSpend(string $sessionId): float
    {
        return $this->sum('e.sessionId = :id', ['id' => $sessionId]);
    }

    public function clientSpend(string $clientKey, \DateTimeImmutable $day): float
    {
        return $this->sum('e.clientKey = :client AND e.spentOn = :day', ['client' => $clientKey, 'day' => $day->format('Y-m-d')]);
    }

    public function daySpend(\DateTimeImmutable $day): float
    {
        return $this->sum('e.spentOn = :day', ['day' => $day->format('Y-m-d')]);
    }

    /** @param array<string, mixed> $parameters */
    private function sum(string $where, array $parameters): float
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(e.usd), 0)')
            ->from($this->entryClass, 'e')
            ->where($where);
        foreach ($parameters as $name => $value) {
            $qb->setParameter($name, $value);
        }

        return (float) $qb->getQuery()->getSingleScalarResult();
    }
}
