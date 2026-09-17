<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Grounding;

use Odiseo\AiAgentBundle\Session\TurnState;

/**
 * A rule reads the caller's message and names one read tool the turn must start with, so an
 * answer of that shape begins from a tool result.
 *
 * $fires returns the input for $tool when the rule applies, else null. $prefetchIntro renders
 * the line a prefetching host puts above the tool result; a rule without one is honoured only
 * where the provider can force the tool, because its input is the model's to write.
 */
final readonly class GroundingRule
{
    /**
     * @param \Closure(string, TurnState): (array<string, mixed>|null) $fires
     * @param (\Closure(array<string, mixed>): string)|null            $prefetchIntro
     */
    public function __construct(
        public string $name,
        public string $tool,
        public \Closure $fires,
        public ?\Closure $prefetchIntro = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function fires(string $text, TurnState $state): ?array
    {
        return ($this->fires)($text, $state);
    }
}
