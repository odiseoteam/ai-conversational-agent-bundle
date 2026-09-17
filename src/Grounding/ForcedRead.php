<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Grounding;

/** The read a turn's first round is pinned to, with the input the rule wrote for it. */
final readonly class ForcedRead
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public GroundingRule $rule,
        public array $input,
    ) {
    }

    public function intro(): ?string
    {
        return null === $this->rule->prefetchIntro ? null : ($this->rule->prefetchIntro)($this->input);
    }
}
