<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Execution;

/**
 * What a tool result says when the tool itself has nothing to say. A vertical overrides these
 * to speak about its own domain; they reach the model, not the person.
 */
final readonly class ExecutorWording
{
    public function __construct(
        public string $displayedText = 'Displayed to the visitor.',
        /** Formatted with {name}. */
        public string $unavailableText = '{name} is temporarily unavailable. Work with what you already have, or tell the visitor.',
        public string $tooManyComponentsText = 'This turn has already presented {count} components; say the rest in text or end the turn.',
        /** Who sees the status line, for the tool schemas. */
        public string $statusReader = 'the visitor',
    ) {
    }
}
