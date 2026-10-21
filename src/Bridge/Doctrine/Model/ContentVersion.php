<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model;

/**
 * One saved version of an editable item. Rows are only added: publishing writes a new one and
 * an item's draft is the row numbered 0, so (kind, item, number) is unique.
 *
 * Mapped superclass (config/orm/ContentVersion.orm.xml).
 */
abstract class ContentVersion implements ContentVersionInterface
{
    protected ?int $id = null;

    protected string $kind = '';

    protected string $itemKey = '';

    protected int $number = 0;

    /** @var array<string, mixed>|null */
    protected ?array $data = null;

    protected ?string $author = null;

    protected ?string $note = null;

    protected \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind;
    }

    public function getItemKey(): string
    {
        return $this->itemKey;
    }

    public function setItemKey(string $itemKey): void
    {
        $this->itemKey = $itemKey;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function setNumber(int $number): void
    {
        $this->number = $number;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): void
    {
        $this->data = $data;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): void
    {
        $this->author = $author;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
