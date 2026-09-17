<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\Stream;

/** A tool call has begun generating. Eager dispatch and progressive cards start here. */
final readonly class ToolCallStarted implements StreamEvent
{
    public function __construct(
        public string $id,
        public string $tool,
    ) {
    }
}
