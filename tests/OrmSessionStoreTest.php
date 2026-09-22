<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Doctrine\ORM\EntityManager;
use Odiseo\AiConversationalAgentBundle\Agent\Transcript;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\ConversationInitializer;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\ConversationInterface;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmSessionStore;
use Odiseo\AiConversationalAgentBundle\Session\SessionConflictException;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestConversation;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestMessage;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Orm;
use PHPUnit\Framework\TestCase;

/** What only the ORM store has to prove: the lock across processes, expiry, the message rows. */
final class OrmSessionStoreTest extends TestCase
{
    public function testAWriterOnAnotherManagerLosesTheRaceToTheOptimisticLock(): void
    {
        $em1 = Orm::entityManager();
        // A second manager over the same connection: another process, in effect, with its own identity map.
        $em2 = new EntityManager($em1->getConnection(), $em1->getConfiguration());
        $store1 = Orm::sessionStore($em1);
        $store2 = Orm::sessionStore($em2);

        $record = $store1->start('visitor-1');
        $first = $store1->require($record->sessionId);
        $second = $store2->require($record->sessionId);

        $first->messages[] = Transcript::userMessage('primero');
        $store1->save($first);

        $second->messages[] = Transcript::userMessage('segundo');
        $this->expectException(SessionConflictException::class);
        $store2->save($second);
    }

    public function testAnExpiredSessionIsNotFoundAndPruneDropsItWithItsMessages(): void
    {
        $em = Orm::entityManager();
        $store = Orm::sessionStore($em, retentionDays: 0);

        $record = $store->start('visitor-1');
        $record->messages[] = Transcript::userMessage('hola');
        $store->save($record);
        $em->clear();

        self::assertNull($store->readState($record->sessionId));
        self::assertSame([], $store->sessionIdsForPrincipal('visitor-1'));

        self::assertSame(1, $store->prune());
        self::assertCount(0, $em->getRepository(TestConversation::class)->findAll());
        self::assertCount(0, $em->getRepository(TestMessage::class)->findAll());
    }

    public function testAMessageRowCarriesItsRoleAndTextBesideThePayload(): void
    {
        $em = Orm::entityManager();
        $store = Orm::sessionStore($em);

        $record = $store->start('visitor-1');
        $record->messages[] = Transcript::userMessage('hola');
        $record->messages[] = ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'search', 'input' => []]]];
        $store->save($record);
        $em->clear();

        /** @var list<TestMessage> $rows */
        $rows = $em->getRepository(TestMessage::class)->findBy([], ['position' => 'ASC']);
        self::assertCount(2, $rows);
        self::assertSame('user', $rows[0]->getRole());
        self::assertSame('hola', $rows[0]->getText());
        self::assertSame('assistant', $rows[1]->getRole());
        self::assertNull($rows[1]->getText());
        self::assertSame('t1', $rows[1]->getPayload()['content'][0]['id']);
    }

    public function testTheHostFillsInItsOwnColumnsWhenTheConversationIsCreated(): void
    {
        $em = Orm::entityManager();
        $initializer = new class implements ConversationInitializer {
            /** @var list<class-string> */
            public array $seen = [];

            public function initialize(ConversationInterface $conversation): void
            {
                $this->seen[] = $conversation::class;
                if ($conversation instanceof TestConversation) {
                    $conversation->setLabel('principal: '.$conversation->getPrincipalId());
                }
            }
        };
        $store = Orm::sessionStore($em, initializer: $initializer);

        $record = $store->start('visitor-1');
        $record->messages[] = Transcript::userMessage('hola');
        $store->save($record);
        $em->clear();

        /** @var TestConversation $row */
        $row = $em->getRepository(TestConversation::class)->findOneBy(['sessionId' => $record->sessionId]);
        self::assertSame('principal: visitor-1', $row->getLabel());
        // Only on the insert, and on the configured entity: a turn never overwrites what the host set.
        self::assertSame([TestConversation::class], $initializer->seen);
    }

    public function testResetLeavesNoRows(): void
    {
        $em = Orm::entityManager();
        $store = Orm::sessionStore($em);

        $record = $store->start('visitor-1');
        $record->messages[] = Transcript::userMessage('hola');
        $store->save($record);
        $store->reset($record);

        self::assertCount(0, $em->getRepository(TestConversation::class)->findAll());
        self::assertCount(0, $em->getRepository(TestMessage::class)->findAll());
        self::assertInstanceOf(OrmSessionStore::class, $store);
    }
}
