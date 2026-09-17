<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider;

/**
 * What an adapter's provider can actually do. The loop reads this and degrades explicitly:
 * without forced tool choice it prefetches the grounding read, without prompt caching it drops
 * the markers and says so in the log, and a capability needing server-side tools is not
 * registered at all.
 */
final readonly class ProviderCapabilities
{
    public function __construct(
        public bool $streaming = true,
        public bool $promptCaching = false,
        public bool $serverTools = false,
        public bool $forcedToolChoice = false,
        public bool $thinking = false,
        public bool $usageAccounting = true,
        public bool $parallelToolCalls = false,
    ) {
    }
}
