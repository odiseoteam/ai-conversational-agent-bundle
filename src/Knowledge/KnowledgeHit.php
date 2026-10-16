<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Knowledge;

/** A section of a document that matched a search, with the text the model reads. */
final readonly class KnowledgeHit
{
    public function __construct(
        public KnowledgeDocument $document,
        public string $content,
        /** The section's heading; null for the text before the first one. */
        public ?string $section = null,
    ) {
    }
}
