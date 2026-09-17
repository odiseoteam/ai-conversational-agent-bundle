<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Presentation;

use Odiseo\AiAgentBundle\Capability\ToolContext;

/**
 * What an enrich hook works with. A hook appends to notes anything the model should hear
 * about the call: ids it dropped, text that was removed.
 */
final class EnrichmentContext
{
    /** @var list<string> */
    private array $notes = [];

    public function __construct(public readonly ToolContext $tools)
    {
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }
}
