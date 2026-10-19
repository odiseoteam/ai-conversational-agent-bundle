<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Capability;

/** One area of what the assistant does ("orders"), for the line it stays within. Higher priority goes first. */
final readonly class ScopeArea
{
    public function __construct(
        public string $text,
        public int $priority = 0,
    ) {
    }
}
