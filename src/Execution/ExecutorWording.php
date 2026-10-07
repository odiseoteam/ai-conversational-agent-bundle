<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Execution;

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
        public string $chipsHeldText = 'The suggestions sent with it were not shown; send the turn\'s suggestions again.',
        /** Who sees the status line, for the tool schemas. */
        public string $statusReader = 'the visitor',
        /** Formatted with {name} and {problems}. */
        public string $invalidInputText = '{name} was not run: {problems}. Correct the arguments and call it again.',
        public string $chipsAloneText = 'The suggestions were not shown: this turn has no reply yet. Write the reply first, then send the suggestions with it.',
    ) {
    }
}
