<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider\Stream;

/**
 * A fragment of a tool call's arguments as they are written. v1 ignores these; they are what a
 * progressive card (ui_partial) is built from.
 */
final readonly class ToolInputChunk implements StreamEvent
{
    public function __construct(
        public string $id,
        public string $tool,
        public string $partialJson,
    ) {
    }
}
