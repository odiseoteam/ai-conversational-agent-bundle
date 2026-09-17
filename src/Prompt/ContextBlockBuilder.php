<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Prompt;

use Odiseo\AiAgentBundle\Fencing\Fence;
use Odiseo\AiAgentBundle\Memory\MemoryFact;

/**
 * The per-request half of the prompt, behind the cache breakpoint and inside the data fence.
 *
 * It is the same bytes from one turn to the next until the state in it changes, which is what
 * lets a turn whose state has not moved read the whole conversation from cache. Anything that
 * changes on every turn — a clock with minutes, a request id — does not belong here.
 */
final class ContextBlockBuilder
{
    public function __construct(private readonly Fence $fence)
    {
    }

    /**
     * @param array<string, mixed> $vertical what the vertical wants in front of the model
     * @param list<MemoryFact>     $facts
     */
    public function build(array $vertical, array $facts, ?\DateTimeImmutable $now = null, int $maxChars = 6000): string
    {
        $payload = $vertical;

        $payload['saved_memory'] = [] === $facts
            ? 'none'
            : array_map(static fn (MemoryFact $fact): array => $fact->toPayload(), $facts);

        if (null !== $now) {
            $payload['local_time'] = PromptAssembler::contextClock($now);
        }

        return "# Session context\n\n".$this->fence->fencePayload($payload, $maxChars);
    }
}
