<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model;

/** What the session store needs of a transcript row. */
interface MessageInterface
{
    public function getId(): ?int;

    public function getConversation(): ?ConversationInterface;

    public function setConversation(?ConversationInterface $conversation): void;

    public function getPosition(): int;

    public function setPosition(int $position): void;

    public function getRole(): string;

    public function setRole(string $role): void;

    public function getText(): ?string;

    public function setText(?string $text): void;

    /** @return array<string, mixed> */
    public function getPayload(): array;

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): void;

    public function getCreatedAt(): \DateTimeImmutable;
}
