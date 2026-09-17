<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Capability;

use Odiseo\AiConversationalAgentBundle\Execution\TurnScope;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use Odiseo\AiConversationalAgentBundle\Streaming\AgentEvent;

/**
 * What a capability's handler works with. It carries the caller (never a user id supplied by
 * the model), the session's state, the vertical's fence and the limits the core enforces.
 */
final readonly class ToolContext
{
    /** @param (\Closure(AgentEvent): void)|null $progress */
    public function __construct(
        public SessionContext $session,
        public TurnState $state,
        public Fence $fence,
        public Limits $limits,
        public TurnScope $scope = new TurnScope(),
        public ?\Closure $progress = null,
    ) {
    }

    public function emit(AgentEvent $event): void
    {
        if (null !== $this->progress) {
            ($this->progress)($event);
        }
    }

    /** A model-supplied count clamped to 1..$ceiling; missing or zero means $default. */
    public function clampLimit(mixed $raw, int $default, int $ceiling): int
    {
        $value = is_numeric($raw) ? (int) $raw : 0;

        return max(1, min(0 === $value ? $default : $value, $ceiling));
    }

    public function sanitize(mixed $value, ?int $maxChars = null): string
    {
        return $this->fence->sanitizeText(\is_scalar($value) || $value instanceof \Stringable ? (string) $value : '', $maxChars);
    }

    public function fenced(mixed $payload): string
    {
        return $this->fence->fencePayload($payload, $this->limits->maxFencedChars);
    }
}
