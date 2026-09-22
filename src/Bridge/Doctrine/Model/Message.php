<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model;

/**
 * One message of a transcript. $payload is the API message as the loop rereads it; $text is
 * its text blocks joined, for a person or a search to read without parsing JSON.
 *
 * Mapped superclass (config/orm/Message.orm.xml); the host's entity names the table and
 * the conversation class it belongs to.
 */
abstract class Message implements MessageInterface
{
    protected ?int $id = null;
    protected ?ConversationInterface $conversation = null;
    protected int $position = 0;
    protected string $role = '';
    protected ?string $text = null;
    /** @var array<string, mixed> */
    protected array $payload = [];
    protected \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ?ConversationInterface
    {
        return $this->conversation;
    }

    public function setConversation(?ConversationInterface $conversation): void
    {
        $this->conversation = $conversation;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = $text;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The text blocks of an API message joined, or null when it carries none (a tool call, a tool result).
     *
     * @param array<string, mixed> $payload
     */
    public static function textOf(array $payload): ?string
    {
        $content = $payload['content'] ?? null;
        if (\is_string($content)) {
            return '' === trim($content) ? null : $content;
        }

        $parts = [];
        foreach (\is_array($content) ? $content : [] as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null) && '' !== trim((string) ($block['text'] ?? ''))) {
                $parts[] = trim((string) $block['text']);
            }
        }

        return [] === $parts ? null : implode("\n", $parts);
    }
}
