<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model;

/**
 * One charged model call. The session is a string, not a relation: the daily and per-client
 * caps are sums over this table and must survive the session being reset or pruned.
 *
 * Mapped superclass (config/doctrine/SpendEntry.orm.xml).
 */
abstract class SpendEntry
{
    protected ?int $id = null;
    protected string $sessionId = '';
    protected ?string $clientKey = null;
    protected \DateTimeImmutable $spentOn;
    protected string $usd = '0';
    protected ?string $model = null;
    protected int $inputTokens = 0;
    protected int $outputTokens = 0;
    protected int $cacheCreationTokens = 0;
    protected int $cacheReadTokens = 0;
    protected \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->spentOn = $now;
        $this->createdAt = $now;
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

    public function getClientKey(): ?string
    {
        return $this->clientKey;
    }

    public function setClientKey(?string $clientKey): void
    {
        $this->clientKey = $clientKey;
    }

    public function getSpentOn(): \DateTimeImmutable
    {
        return $this->spentOn;
    }

    public function setSpentOn(\DateTimeImmutable $spentOn): void
    {
        $this->spentOn = $spentOn;
    }

    /** Decimal as a string, the way Doctrine hands a decimal column back. */
    public function getUsd(): string
    {
        return $this->usd;
    }

    public function setUsd(float $usd): void
    {
        $this->usd = number_format($usd, 6, '.', '');
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): void
    {
        $this->model = $model;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getCacheCreationTokens(): int
    {
        return $this->cacheCreationTokens;
    }

    public function getCacheReadTokens(): int
    {
        return $this->cacheReadTokens;
    }

    public function setTokens(int $input, int $output, int $cacheCreation, int $cacheRead): void
    {
        $this->inputTokens = $input;
        $this->outputTokens = $output;
        $this->cacheCreationTokens = $cacheCreation;
        $this->cacheReadTokens = $cacheRead;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
