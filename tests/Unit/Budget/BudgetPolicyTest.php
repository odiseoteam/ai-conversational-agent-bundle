<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Budget;

use Odiseo\AiConversationalAgentBundle\Budget\BudgetLimit;
use Odiseo\AiConversationalAgentBundle\Budget\BudgetPolicy;
use Odiseo\AiConversationalAgentBundle\Budget\CostTable;
use Odiseo\AiConversationalAgentBundle\Budget\InMemorySpendLedger;
use Odiseo\AiConversationalAgentBundle\Budget\NullClientKeyResolver;
use Odiseo\AiConversationalAgentBundle\Budget\SpendScopeResolverInterface;
use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BudgetPolicyTest extends TestCase
{
    public function testAChargeIsRecordedUnderTheScope(): void
    {
        $ledger = new InMemorySpendLedger();
        $at = new \DateTimeImmutable('2026-10-17 10:00');

        $this->policy(new AgentConfig(), $ledger, 'OUTLET')->charge('s1', 'claude-haiku-5-5', new Usage(1_000_000, 0), $at);

        self::assertEqualsWithDelta(0.1, $ledger->scopeSpend('OUTLET', $at), 1e-9);
    }

    public function testAScopeStopsAtItsOwnDayCap(): void
    {
        $ledger = new InMemorySpendLedger();
        $at = new \DateTimeImmutable('2026-10-17 10:00');
        $ledger->record('other', $at, 0.3, scope: 'OUTLET');

        $capped = new AgentConfig(dailyBudgetUsd: 20.0, scopeDailyBudgetUsd: 0.25);

        self::assertSame(BudgetLimit::Day, $this->policy($capped, $ledger, 'OUTLET')->exceeded('s1', $at));
        self::assertNull($this->policy($capped, $ledger, 'WEB')->exceeded('s1', $at));
        self::assertNull($this->policy(new AgentConfig(dailyBudgetUsd: 20.0), $ledger, 'OUTLET')->exceeded('s1', $at));
    }

    private function policy(AgentConfig $config, InMemorySpendLedger $ledger, ?string $scope): BudgetPolicy
    {
        return new BudgetPolicy($config, $ledger, new CostTable(), new NullClientKeyResolver(), new NullLogger(), new class($scope) implements SpendScopeResolverInterface {
            public function __construct(private readonly ?string $scope)
            {
            }

            public function scope(): ?string
            {
                return $this->scope;
            }
        });
    }
}
