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
     * $partial builds a payload from the arguments as they are still being written (open
     * lists closed, half-written strings left out): it must tolerate any field missing and
     * throw on nothing. A component without one renders only once its call is complete.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed>                                  $validate
     * @param (\Closure(array<string, mixed>, EnrichmentContext): array<string, mixed>)|null        $enrich
     * @param (\Closure(array<string, mixed>, EnrichmentContext): (array<string, mixed>|null))|null $partial
     */
    public function __construct(
        public string $tool,
        public string $component,
        public \Closure $validate,
        public ?\Closure $enrich = null,
        public ?\Closure $partial = null,
    ) {
    }
}
