<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\Request;

/**
 * How the model may use tools this round. A forced choice is how a grounding rule pins the
 * first round to one read; a provider without that capability makes the host prefetch instead.
 */
final readonly class ToolChoice
{
    public function __construct(
        public string $type = 'auto',
        public ?string $tool = null,
    ) {
    }

    public static function auto(): self
    {
        return new self('auto');
    }

    public static function none(): self
    {
        return new self('none');
    }

    public static function forced(string $tool): self
    {
        return new self('tool', $tool);
    }

    public function isAuto(): bool
    {
        return 'auto' === $this->type;
    }
}
