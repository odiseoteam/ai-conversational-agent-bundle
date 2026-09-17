<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider\Response;

final readonly class ToolUse
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
    ) {
    }
}
