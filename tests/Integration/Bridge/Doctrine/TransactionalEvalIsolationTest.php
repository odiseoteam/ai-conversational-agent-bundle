<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Integration\Bridge\Doctrine;

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
}
