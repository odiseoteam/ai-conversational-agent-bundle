<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

/**
 * USD per million tokens. Cache writes and cache reads are priced apart from plain input, each
 * stated as the provider lists it: their ratio to input is not the same everywhere.
 */
final readonly class ModelPrice
{
    public function __construct(
        public float $inputPerMillion,
        public float $outputPerMillion,
        public float $cacheWritePerMillion,
        public float $cacheReadPerMillion,
    ) {
    }

    /** @param array{input: float|int, output: float|int, cache_write: float|int, cache_read: float|int} $price */
    public static function fromArray(array $price): self
    {
        return new self((float) $price['input'], (float) $price['output'], (float) $price['cache_write'], (float) $price['cache_read']);
    }
}
