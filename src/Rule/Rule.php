<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Rule;

/**
 * A behaviour rule the organisation sets for its assistant. Only the text reaches the prompt;
 * the id and title name it for whoever edits or measures it.
 */
final readonly class Rule
{
    public function __construct(
        public string $id,
        public string $title,
        public RuleCategory $category,
        public string $text,
        public bool $enabled = true,
    ) {
    }
}
