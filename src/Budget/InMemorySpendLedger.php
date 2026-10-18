<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;

final class InMemorySpendLedger implements SpendLedgerInterface
{
    /** @var array<string, float> */
    private array $bySession = [];

    /** @var array<string, float> */
    private array $byClientDay = [];

    /** @var array<string, float> */
    private array $byDay = [];

    /** @var array<string, float> */
    private array $byScopeDay = [];

    public function record(string $sessionId, \DateTimeImmutable $at, float $usd, ?string $clientKey = null, ?string $model = null, ?Usage $usage = null, ?string $scope = null): void
    {
        $this->bySession[$sessionId] = ($this->bySession[$sessionId] ?? 0.0) + $usd;
        $day = $at->format('Y-m-d');
        $this->byDay[$day] = ($this->byDay[$day] ?? 0.0) + $usd;
        if (null !== $clientKey) {
            $this->byClientDay[$clientKey.'|'.$day] = ($this->byClientDay[$clientKey.'|'.$day] ?? 0.0) + $usd;
        }
        if (null !== $scope) {
            $this->byScopeDay[$scope.'|'.$day] = ($this->byScopeDay[$scope.'|'.$day] ?? 0.0) + $usd;
        }
    }

    public function sessionSpend(string $sessionId): float
    {
        return $this->bySession[$sessionId] ?? 0.0;
    }

    public function clientSpend(string $clientKey, \DateTimeImmutable $day): float
    {
        return $this->byClientDay[$clientKey.'|'.$day->format('Y-m-d')] ?? 0.0;
    }

    public function daySpend(\DateTimeImmutable $day): float
    {
        return $this->byDay[$day->format('Y-m-d')] ?? 0.0;
    }

    public function scopeSpend(string $scope, \DateTimeImmutable $day): float
    {
        return $this->byScopeDay[$scope.'|'.$day->format('Y-m-d')] ?? 0.0;
    }
}
