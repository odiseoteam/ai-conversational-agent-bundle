<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model;

/**
 * What the session store needs of a conversation row. A host that cannot extend the mapped
 * superclass implements this instead; the store never names a concrete class.
 */
interface ConversationInterface
{
    public function getId(): ?int;

    public function getSessionId(): string;

    public function setSessionId(string $sessionId): void;

    public function getPrincipalId(): string;

    public function setPrincipalId(string $principalId): void;

    public function getVersion(): int;

    /** @return array<string, mixed> */
    public function getState(): array;

    /** @param array<string, mixed> $state */
    public function setState(array $state): void;

    /** @return list<string> */
    public function getPendingAppEvents(): array;

    /** @param list<string> $events */
    public function setPendingAppEvents(array $events): void;

    public function getCreatedAt(): \DateTimeImmutable;

    public function getUpdatedAt(): \DateTimeImmutable;

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void;

    public function getExpiresAt(): \DateTimeImmutable;

    public function setExpiresAt(\DateTimeImmutable $expiresAt): void;
}
