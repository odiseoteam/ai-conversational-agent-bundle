<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Presentation;

/**
 * One presentation tool: the component the host renders, the validator that turns the model's
 * arguments into a payload, and the hook that joins server data onto it.
 *
 * The model selects and annotates; every fact on the component is joined server-side, so an
 * id the model made up does not reach the person as a price or a title.
 */
final readonly class PresentationComponent
{
    /**
     * @param \Closure(array<string, mixed>): array<string, mixed>                           $validate
     * @param (\Closure(array<string, mixed>, EnrichmentContext): array<string, mixed>)|null $enrich
     */
    public function __construct(
        public string $tool,
        public string $component,
        public \Closure $validate,
        public ?\Closure $enrich = null,
    ) {
    }
}
