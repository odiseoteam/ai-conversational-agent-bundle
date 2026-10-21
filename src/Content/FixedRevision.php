<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Content;

/** Nothing is published at runtime: what is built once stays. */
final class FixedRevision implements RevisionInterface
{
    public function revision(): string
    {
        return '';
    }
}
