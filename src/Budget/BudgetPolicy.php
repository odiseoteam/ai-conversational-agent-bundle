<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

use Odiseo\AiConversationalAgentBundle\Config\AgentConfigProviderInterface;
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
        private readonly AgentConfigProviderInterface $config,
        private readonly SpendLedgerInterface $ledger,
        private readonly CostTable $costs = new CostTable(),
        private readonly ClientKeyResolverInterface $client = new NullClientKeyResolver(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly SpendScopeResolverInterface $scope = new NullSpendScopeResolver(),
    ) {
    }

    public function exceeded(string $sessionId, \DateTimeImmutable $at): ?BudgetLimit
    {
        $config = $this->config->agentConfig();
        if ($this->ledger->sessionSpend($sessionId) >= $config->sessionBudgetUsd) {
            return BudgetLimit::Session;
        }

        $cap = $config->clientBudgetUsd;
        $client = null === $cap ? null : $this->client->clientKey();
        if (null !== $cap && null !== $client && $this->ledger->clientSpend($client, $at) >= $cap) {
            return BudgetLimit::Client;
        }

        if ($this->ledger->daySpend($at) >= $config->dailyBudgetUsd) {
            return BudgetLimit::Day;
        }

        $cap = $config->scopeDailyBudgetUsd;
        $scope = null === $cap ? null : $this->scope->scope();
        if (null !== $cap && null !== $scope && $this->ledger->scopeSpend($scope, $at) >= $cap) {
            return BudgetLimit::Day;
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
        $this->ledger->record($sessionId, $at, $cost, $this->client->clientKey(), $model, $usage, $this->scope->scope());

        return $cost;
    }
}
