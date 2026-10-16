<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Knowledge;

/**
 * A document of the organisation's knowledge: a policy, a guide or a short snippet. The body is
 * Markdown; its `##` headings split it into the sections a search returns.
 */
final readonly class KnowledgeDocument
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public KnowledgeKind $kind = KnowledgeKind::Policy,
        /** When to use it, in a line. */
        public string $when = '',
        public ?string $url = null,
    ) {
    }
}
