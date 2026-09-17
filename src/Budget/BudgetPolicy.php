<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The spend caps, checked before each model call and charged after it. A turn that crosses a
 * cap mid-way finishes the round it is in and then stops, so the person is never left with a
 * half-written reply and an unanswered tool call.
 */
final class BudgetPolicy
{
    public function __construct(
        private readonly AgentConfig $config,
        private readonly SpendLedger $ledger,
        private readonly CostTable $costs = new CostTable(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function exceeded(string $sessionId, \DateTimeImmutable $at): ?BudgetExceeded
    {
        if ($this->ledger->sessionSpend($sessionId) >= $this->config->sessionBudgetUsd) {
            return BudgetExceeded::Session;
        }

        if ($this->ledger->daySpend($at) >= $this->config->dailyBudgetUsd) {
            return BudgetExceeded::Day;
        }

        return null;
    }

    public function charge(string $sessionId, string $model, Usage $usage, \DateTimeImmutable $at): float
    {
        if (!$this->costs->knows($model)) {
            $this->logger->warning('model {model} has no price; this call is not charged to any budget', ['model' => $model]);

            return 0.0;
        }

        $cost = $this->costs->costOf($model, $usage);
        $this->ledger->record($sessionId, $at, $cost);

        return $cost;
    }
}
