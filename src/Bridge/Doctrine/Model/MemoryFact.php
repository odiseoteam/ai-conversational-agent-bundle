<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model;

use Odiseo\AiConversationalAgentBundle\Memory\MemoryCategory;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryFact as Fact;

/**
 * One long-term fact, keyed by subject and key. No relation to anything: the subject is a
 * string that outlives any conversation and the core does not know who it names.
 *
 * Mapped superclass (config/doctrine/MemoryFact.orm.xml).
 */
abstract class MemoryFact
{
    protected ?int $id = null;
    protected string $subject = '';
    protected string $factKey = '';
    protected string $factValue = '';
    protected string $category = '';
    protected ?string $sourceSession = null;
    protected \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }

    public function getFactKey(): string
    {
        return $this->factKey;
    }

    public function setFactKey(string $factKey): void
    {
        $this->factKey = $factKey;
    }

    public function getFactValue(): string
    {
        return $this->factValue;
    }

    public function setFactValue(string $factValue): void
    {
        $this->factValue = $factValue;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): void
    {
        $this->category = $category;
    }

    public function getSourceSession(): ?string
    {
        return $this->sourceSession;
    }

    public function setSourceSession(?string $sourceSession): void
    {
        $this->sourceSession = $sourceSession;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /** The row as the runtime reads it. */
    public function toFact(): Fact
    {
        return new Fact(
            $this->factKey,
            $this->factValue,
            MemoryCategory::tryFrom($this->category) ?? MemoryCategory::Preference,
            $this->updatedAt,
            $this->sourceSession,
        );
    }

    public function fill(Fact $fact): void
    {
        $this->factKey = $fact->key;
        $this->factValue = $fact->value;
        $this->category = $fact->category->value;
        $this->sourceSession = $fact->sourceSessionTag;
        $this->updatedAt = $fact->updatedAt ?? new \DateTimeImmutable();
    }
}
