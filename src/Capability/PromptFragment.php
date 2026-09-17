<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Capability;

/**
 * A capability's contribution to the static system prompt. Ordering is by section, then
 * priority, then capability name, so the assembled prompt is byte-identical across turns —
 * which is what keeps the cache prefix stable.
 */
final readonly class PromptFragment
{
    public function __construct(
        public PromptSection $section,
        public string $text,
        public int $priority = 0,
    ) {
    }
}
