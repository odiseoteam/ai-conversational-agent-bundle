<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model;

/** What the version store needs of a saved version. */
interface ContentVersionInterface
{
    public function getId(): ?int;

    public function getKind(): string;

    public function setKind(string $kind): void;

    public function getItemKey(): string;

    public function setItemKey(string $itemKey): void;

    /** 0 for a draft. */
    public function getNumber(): int;

    public function setNumber(int $number): void;

    /** @return array<string, mixed>|null null is "back to the default" */
    public function getData(): ?array;

    /** @param array<string, mixed>|null $data */
    public function setData(?array $data): void;

    public function getAuthor(): ?string;

    public function setAuthor(?string $author): void;

    public function getNote(): ?string;

    public function setNote(?string $note): void;

    public function getCreatedAt(): \DateTimeImmutable;

    public function setCreatedAt(\DateTimeImmutable $createdAt): void;
}
