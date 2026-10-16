<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Knowledge;

/**
 * Where documents come from: files, a database, a CMS, or ones the system builds from its own
 * data. The lexical search ranks the documents of every source together.
 */
interface KnowledgeSourceInterface
{
    /** @return iterable<KnowledgeDocument> */
    public function documents(): iterable;
}
