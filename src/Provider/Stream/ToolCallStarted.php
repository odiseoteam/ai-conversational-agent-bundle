<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider\Stream;

/** A tool call has begun generating. Eager dispatch and progressive cards start here. */
final readonly class ToolCallStarted implements StreamEvent
{
    public function __construct(
        public string $id,
        public string $tool,
    ) {
    }
}
