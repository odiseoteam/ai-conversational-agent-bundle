<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Knowledge;

/**
 * A search over the knowledge that ranks its own hits: the core's lexical search, or a search
 * engine (Elasticsearch, a vector store) over documents it indexes itself. The knowledge base
 * merges the searches by position.
 */
interface KnowledgeSearchInterface
{
    /** @return list<KnowledgeHit> best first */
    public function search(string $query, int $limit): array;
}
