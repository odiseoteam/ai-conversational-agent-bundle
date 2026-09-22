<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Skill;

final readonly class Skill
{
    /** @param list<string> $requires tools the flow needs; without every one of them the skill is not offered */
    public function __construct(
        public string $name,
        public string $description,
        public string $body,
        public array $requires = [],
    ) {
    }

    /** @param list<string> $tools */
    public function availableWith(array $tools): bool
    {
        return [] === array_diff($this->requires, $tools);
    }
}
