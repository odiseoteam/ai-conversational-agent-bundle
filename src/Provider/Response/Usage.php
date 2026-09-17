<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider\Response;

/**
 * What one model call consumed. Cache reads and cache writes are kept apart because they are
 * priced apart, and because a cache-read count of zero is how a broken prefix shows up.
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheCreationTokens = 0,
        public int $cacheReadTokens = 0,
    ) {
    }

    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheCreationTokens + $other->cacheCreationTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
        );
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_creation_input_tokens' => $this->cacheCreationTokens,
            'cache_read_input_tokens' => $this->cacheReadTokens,
        ];
    }

    /** The tokens the prompt cost this call, however they were served. */
    public function promptTokens(): int
    {
        return $this->inputTokens + $this->cacheCreationTokens + $this->cacheReadTokens;
    }
}
