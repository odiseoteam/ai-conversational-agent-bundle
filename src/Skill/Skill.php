<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Skill;

final readonly class Skill
{
    public function __construct(
        public string $name,
        public string $description,
        public string $body,
    ) {
    }
}
