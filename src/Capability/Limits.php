<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Capability;

/**
 * The count caps the core enforces on every path: how much of a tool result reaches the
 * model, how many results a read may return, and how much a turn may present.
 */
final readonly class Limits
{
    public function __construct(
        public int $maxFencedChars = 12_000,
        public int $maxResultsPerCall = 8,
        public int $maxComponentsPerTurn = 3,
        public int $maxChipsPerTurn = 4,
    ) {
    }
}
