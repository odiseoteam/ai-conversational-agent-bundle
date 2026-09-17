<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider\Request;

/**
 * One block of the system prompt. $cacheHint asks the provider to make everything up to and
 * including this block cacheable; a provider without prompt caching ignores it.
 */
final readonly class SystemBlock
{
    public function __construct(
        public string $text,
        public bool $cacheHint = false,
    ) {
    }
}
