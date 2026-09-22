<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model;

/**
 * One session as the ORM keeps it: the principal, the turn state and the pending app events
 * that SessionStore reads as a document, under an optimistic lock on $version.
 *
 * Mapped superclass (config/orm/Conversation.orm.xml): a host extends it, names the
 * table and adds what it relates to (a customer, an order). The core only knows strings.
 */
abstract class Conversation implements ConversationInterface
{
    protected ?int $id = null;
    protected string $sessionId = '';
    protected string $principalId = '';
    protected int $version = 1;
    /** @var array<string, mixed> */
    protected array $state = [];
    /** @var list<string> */
    protected array $pendingAppEvents = [];
    protected \DateTimeImmutable $createdAt;
    protected \DateTimeImmutable $updatedAt;
    protected \DateTimeImmutable $expiresAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->expiresAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getPrincipalId(): string
    {
        return $this->principalId;
    }

    public function setPrincipalId(string $principalId): void
    {
        $this->principalId = $principalId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function getState(): array
    {
        return $this->state;
    }

    /** @param array<string, mixed> $state */
    public function setState(array $state): void
    {
        $this->state = $state;
    }

    /** @return list<string> */
    public function getPendingAppEvents(): array
    {
        return $this->pendingAppEvents;
    }

    /** @param list<string> $events */
    public function setPendingAppEvents(array $events): void
    {
        $this->pendingAppEvents = $events;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }
}
