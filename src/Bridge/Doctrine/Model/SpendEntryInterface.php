<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model;

/** What the spend ledger needs of a charged call. */
interface SpendEntryInterface
{
    public function getId(): ?int;

    public function getSessionId(): string;

    public function setSessionId(string $sessionId): void;

    public function getClientKey(): ?string;

    public function setClientKey(?string $clientKey): void;

    public function getSpentOn(): \DateTimeImmutable;

    public function setSpentOn(\DateTimeImmutable $spentOn): void;

    /** Decimal as a string, the way Doctrine hands a decimal column back. */
    public function getUsd(): string;

    public function setUsd(float $usd): void;

    public function getModel(): ?string;

    public function setModel(?string $model): void;

    public function getInputTokens(): int;

    public function getOutputTokens(): int;

    public function getCacheCreationTokens(): int;

    public function getCacheReadTokens(): int;

    public function setTokens(int $input, int $output, int $cacheCreation, int $cacheRead): void;

    public function getCreatedAt(): \DateTimeImmutable;
}
