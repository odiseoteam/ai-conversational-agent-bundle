<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Presentation;

/**
 * What a still-streaming presentation call shows so far: the component, the payload built
 * from the arguments written up to now, and a key that changes only when something visible
 * changed (a title appearing, one more entry in a list), so the host is not sent a frame per
 * token.
 */
final readonly class PartialFrame
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $component,
        public array $payload,
        public string $key,
    ) {
    }
}
