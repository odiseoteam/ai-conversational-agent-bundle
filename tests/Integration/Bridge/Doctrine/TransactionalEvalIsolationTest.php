<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Integration\Bridge\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\TransactionalEvalIsolation;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryFact;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Orm;
use PHPUnit\Framework\TestCase;

/** What a case writes through the ORM is gone once it ends, even when it throws. */
final class TransactionalEvalIsolationTest extends TestCase
{
    public function testWhatTheCaseWroteIsRolledBack(): void
    {
        $em = Orm::entityManager();
        $sessions = Orm::sessionStore($em);
        $memory = Orm::memoryStore($em);
        $ledger = Orm::spendLedger($em);
        $isolation = new TransactionalEvalIsolation($em);

        $sessionId = $isolation->isolate(static function () use ($sessions, $memory, $ledger): string {
            $record = $sessions->start('eval-subject');
            $sessions->save($record);
            $memory->save('eval-subject', new MemoryFact('size', 'M'));
            $ledger->record($record->sessionId, new \DateTimeImmutable(), 0.5);

            return $record->sessionId;
        });

        self::assertNull($sessions->readState($sessionId));
        self::assertSame([], $memory->all('eval-subject'));
        self::assertSame(0.0, $ledger->sessionSpend($sessionId));
    }

    public function testATransactionAroundTheRunStaysOpen(): void
    {
        $em = Orm::entityManager();
        $memory = Orm::memoryStore($em);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        (new TransactionalEvalIsolation($em))->isolate(static function () use ($memory): void {
            $memory->save('eval-subject', new MemoryFact('size', 'M'));
        });

        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame([], $memory->all('eval-subject'));
        $connection->rollBack();
    }

    public function testACaseThatThrowsIsRolledBackToo(): void
    {
        $em = Orm::entityManager();
        $memory = Orm::memoryStore($em);

        try {
            (new TransactionalEvalIsolation($em))->isolate(static function () use ($memory): never {
                $memory->save('eval-subject', new MemoryFact('size', 'M'));

                throw new \RuntimeException('provider down');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $memory->all('eval-subject'));
        self::assertFalse($em->getConnection()->isTransactionActive());
    }

    public function testAManagerAFailedFlushClosedIsReset(): void
    {
        $em = Orm::entityManager();
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');

        try {
            (new TransactionalEvalIsolation($em, $registry))->isolate(static function () use ($em): never {
                $em->close();

                throw new \RuntimeException('flush failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertFalse($em->getConnection()->isTransactionActive());
    }
}
