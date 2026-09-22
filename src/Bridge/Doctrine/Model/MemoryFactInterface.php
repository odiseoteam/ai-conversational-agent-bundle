<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model;

use Odiseo\AiConversationalAgentBundle\Memory\MemoryFact as Fact;

/** What the memory store needs of a fact row, including the bridge to and from the runtime fact. */
interface MemoryFactInterface
{
    public function getId(): ?int;

    public function getSubject(): string;

    public function setSubject(string $subject): void;

    public function getFactKey(): string;

    public function setFactKey(string $factKey): void;

    public function getFactValue(): string;

    public function setFactValue(string $factValue): void;

    public function getCategory(): string;

    public function setCategory(string $category): void;

    public function getSourceSession(): ?string;

    public function setSourceSession(?string $sourceSession): void;

    public function getUpdatedAt(): \DateTimeImmutable;

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void;

    /** The row as the runtime reads it. */
    public function toFact(): Fact;

    public function fill(Fact $fact): void;
}
