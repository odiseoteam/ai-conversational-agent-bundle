<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Streaming;

/**
 * One tool call's product: $resultText for the model, $events for the host. $blocked names
 * the gate that held the call; $isError marks a failure.
 */
final readonly class ToolOutcome
{
    /** @param list<AgentEvent> $events */
    public function __construct(
        public string $resultText,
        public array $events = [],
        public bool $isError = false,
        public ?string $blocked = null,
    ) {
    }

    public static function error(string $text): self
    {
        return new self($text, isError: true);
    }

    public static function held(string $gate, string $text): self
    {
        return new self($text, blocked: $gate);
    }

    public function refused(): bool
    {
        return $this->isError || null !== $this->blocked;
    }
}
