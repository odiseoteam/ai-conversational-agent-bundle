<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\ConversationInitializer;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\ConversationInterface;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\Message;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\MessageInterface;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\NullConversationInitializer;
use Odiseo\AiConversationalAgentBundle\Session\SessionConflictException;
use Odiseo\AiConversationalAgentBundle\Session\SessionStore;

/**
 * The session store over the ORM: a conversation row for the state document, a message row
 * per transcript entry.
 *
 * The compare-and-set on the version is Doctrine's optimistic lock: the UPDATE carries
 * `WHERE version = :expected` and a lost race surfaces as OptimisticLockException, here as
 * SessionConflictException. Two starts racing on one id meet the unique constraint instead.
 * Expiry is a column and prune(), not a background sweep.
 */
final class OrmSessionStore extends SessionStore
{
    /**
     * @param class-string<ConversationInterface> $conversationClass
     * @param class-string<MessageInterface>      $messageClass
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $conversationClass,
        private readonly string $messageClass,
        private readonly int $retentionDays = 30,
        private readonly ConversationInitializer $initializer = new NullConversationInitializer(),
    ) {
    }

    public function readState(string $sessionId): ?array
    {
        $conversation = $this->find($sessionId);
        if (null === $conversation || $conversation->getExpiresAt() <= new \DateTimeImmutable()) {
            return null;
        }

        return [$conversation->getVersion(), self::document($conversation)];
    }

    public function writeState(string $sessionId, array $document, int $version): void
    {
        if (0 === $version) {
            $this->insert($sessionId, $document);

            return;
        }

        $conversation = $this->find($sessionId);
        if (null === $conversation || $conversation->getVersion() !== $version) {
            throw new SessionConflictException($sessionId);
        }

        self::fill($conversation, $document, $this->expiry());

        try {
            $this->em->lock($conversation, LockMode::OPTIMISTIC, $version);
            $this->em->flush();
        } catch (OptimisticLockException) {
            throw new SessionConflictException($sessionId);
        }
    }

    public function readMessages(string $sessionId): array
    {
        $conversation = $this->find($sessionId);
        if (null === $conversation) {
            return [];
        }

        /** @var list<MessageInterface> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('m')
            ->from($this->messageClass, 'm')
            ->where('m.conversation = :conversation')
            ->orderBy('m.position', 'ASC')
            ->setParameter('conversation', $conversation)
            ->getQuery()
            ->getResult();

        return array_map(static fn (MessageInterface $row): array => $row->getPayload(), $rows);
    }

    public function writeMessages(string $sessionId, array $messages, int $start): void
    {
        $conversation = $this->find($sessionId);
        if (null === $conversation) {
            throw new SessionConflictException($sessionId);
        }

        $this->em->createQueryBuilder()
            ->delete($this->messageClass, 'm')
            ->where('m.conversation = :conversation AND m.position >= :start')
            ->setParameter('conversation', $conversation)
            ->setParameter('start', $start)
            ->getQuery()
            ->execute();

        foreach ($messages as $offset => $payload) {
            $message = new $this->messageClass();
            $message->setConversation($conversation);
            $message->setPosition($start + $offset);
            $message->setRole((string) ($payload['role'] ?? ''));
            $message->setText(Message::textOf($payload));
            $message->setPayload($payload);
            $this->em->persist($message);
        }

        $this->em->flush();
    }

    public function delete(string $sessionId): void
    {
        $conversation = $this->find($sessionId);
        if (null === $conversation) {
            return;
        }

        $this->deleteMessagesOf([$conversation]);
        $this->em->remove($conversation);
        $this->em->flush();
    }

    public function sessionIdsForPrincipal(string $principalId): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->createQueryBuilder()
            ->select('c.sessionId')
            ->from($this->conversationClass, 'c')
            ->where('c.principalId = :principal AND c.expiresAt > :now')
            ->orderBy('c.updatedAt', 'DESC')
            ->setParameter('principal', $principalId)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('strval', $ids);
    }

    /** Drop what has expired. Called by a prune command, not on the request path. */
    public function prune(): int
    {
        /** @var list<ConversationInterface> $expired */
        $expired = $this->em->createQueryBuilder()
            ->select('c')
            ->from($this->conversationClass, 'c')
            ->where('c.expiresAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();

        if ([] === $expired) {
            return 0;
        }

        $this->deleteMessagesOf($expired);
        foreach ($expired as $conversation) {
            $this->em->remove($conversation);
        }
        $this->em->flush();

        return \count($expired);
    }

    /** @param array<string, mixed> $document */
    private function insert(string $sessionId, array $document): void
    {
        $conversation = new $this->conversationClass();
        $conversation->setSessionId($sessionId);
        $conversation->setPrincipalId((string) ($document['principal_id'] ?? ''));
        self::fill($conversation, $document, $this->expiry());
        $this->initializer->initialize($conversation);

        try {
            $this->em->persist($conversation);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new SessionConflictException($sessionId);
        }
    }

    private function find(string $sessionId): ?ConversationInterface
    {
        /** @var ConversationInterface|null $conversation */
        $conversation = $this->em->getRepository($this->conversationClass)->findOneBy(['sessionId' => $sessionId]);

        return $conversation;
    }

    /** @param list<ConversationInterface> $conversations */
    private function deleteMessagesOf(array $conversations): void
    {
        $this->em->createQueryBuilder()
            ->delete($this->messageClass, 'm')
            ->where('m.conversation IN (:conversations)')
            ->setParameter('conversations', $conversations)
            ->getQuery()
            ->execute();
    }

    /** @param array<string, mixed> $document */
    private static function fill(ConversationInterface $conversation, array $document, \DateTimeImmutable $expiresAt): void
    {
        $conversation->setState(\is_array($document['state'] ?? null) ? $document['state'] : []);
        $conversation->setPendingAppEvents(array_values(array_map(
            'strval',
            \is_array($document['pending_app_events'] ?? null) ? $document['pending_app_events'] : [],
        )));
        $conversation->setUpdatedAt(new \DateTimeImmutable());
        $conversation->setExpiresAt($expiresAt);
    }

    /** @return array<string, mixed> */
    private static function document(ConversationInterface $conversation): array
    {
        return [
            'principal_id' => $conversation->getPrincipalId(),
            'state' => $conversation->getState(),
            'pending_app_events' => $conversation->getPendingAppEvents(),
        ];
    }

    private function expiry(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('+%d days', $this->retentionDays));
    }
}
