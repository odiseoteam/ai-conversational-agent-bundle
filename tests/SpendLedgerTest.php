<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Budget\InMemorySpendLedger;
use Odiseo\AiConversationalAgentBundle\Budget\SpendLedger;
use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestSpendEntry;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Orm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpendLedgerTest extends TestCase
{
    /** @return iterable<string, array{0: \Closure(): SpendLedger}> */
    public static function ledgers(): iterable
    {
        yield 'memory' => [static fn (): SpendLedger => new InMemorySpendLedger()];
        yield 'orm' => [static fn (): SpendLedger => Orm::spendLedger()];
    }

    /** @param \Closure(): SpendLedger $make */
    #[DataProvider('ledgers')]
    public function testSpendSumsBySessionClientAndDay(\Closure $make): void
    {
        $ledger = $make();
        $monday = new \DateTimeImmutable('2026-09-21 10:00');
        $tuesday = new \DateTimeImmutable('2026-09-22 09:00');

        $ledger->record('s1', $monday, 0.01, 'ip-a');
        $ledger->record('s1', $monday, 0.02, 'ip-a');
        $ledger->record('s2', $monday, 0.04, 'ip-b');
        $ledger->record('s1', $tuesday, 0.08, 'ip-a');

        self::assertEqualsWithDelta(0.11, $ledger->sessionSpend('s1'), 1e-9);
        self::assertEqualsWithDelta(0.03, $ledger->clientSpend('ip-a', $monday), 1e-9);
        self::assertEqualsWithDelta(0.07, $ledger->daySpend($monday), 1e-9);
        self::assertEqualsWithDelta(0.0, $ledger->daySpend(new \DateTimeImmutable('2026-09-23')), 1e-9);
    }

    public function testTheOrmLedgerKeepsTheModelAndTheTokensAndSkipsAFreeCall(): void
    {
        $em = Orm::entityManager();
        $ledger = Orm::spendLedger($em);

        $ledger->record('s1', new \DateTimeImmutable('2026-09-22'), 0.0, 'ip-a', 'claude-haiku-4-5-20251001', new Usage(10, 5));
        $ledger->record('s1', new \DateTimeImmutable('2026-09-22'), 0.0123456, 'ip-a', 'claude-sonnet-5', new Usage(1000, 200, 300, 4000));

        /** @var list<TestSpendEntry> $rows */
        $rows = $em->getRepository(TestSpendEntry::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('claude-sonnet-5', $rows[0]->getModel());
        self::assertSame('0.012346', $rows[0]->getUsd());
        self::assertSame([1000, 200, 300, 4000], [$rows[0]->getInputTokens(), $rows[0]->getOutputTokens(), $rows[0]->getCacheCreationTokens(), $rows[0]->getCacheReadTokens()]);
    }

    public function testTheOrmLedgerForgetsTheSessionsItIsGiven(): void
    {
        $em = Orm::entityManager();
        $ledger = Orm::spendLedger($em);
        $ledger->record('old', new \DateTimeImmutable('2026-03-01'), 0.01);
        $ledger->record('old', new \DateTimeImmutable('2026-03-01'), 0.02);
        $ledger->record('new', new \DateTimeImmutable('2026-09-22'), 0.03);

        self::assertSame(2, $ledger->forget(['old'], dryRun: true));
        self::assertSame(2, $ledger->forget(['old']));
        self::assertEqualsWithDelta(0.0, $ledger->sessionSpend('old'), 1e-9);
        self::assertEqualsWithDelta(0.03, $ledger->sessionSpend('new'), 1e-9);
    }
}
