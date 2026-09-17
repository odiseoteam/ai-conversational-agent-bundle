<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

/** USD per million tokens. Cache writes and cache reads are priced apart from plain input. */
final readonly class ModelPrice
{
    public function __construct(
        public float $inputPerMillion,
        public float $outputPerMillion,
        public ?float $cacheWritePerMillion = null,
        public ?float $cacheReadPerMillion = null,
    ) {
    }

    public function cacheWrite(): float
    {
        return $this->cacheWritePerMillion ?? $this->inputPerMillion * 1.25;
    }

    public function cacheRead(): float
    {
        return $this->cacheReadPerMillion ?? $this->inputPerMillion * 0.1;
    }
}
