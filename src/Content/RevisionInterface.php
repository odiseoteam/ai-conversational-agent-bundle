<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Content;

/**
 * Changes whenever something is published, in every process that reads it: what is built from
 * published items (the house rules, the guide index) is rebuilt when it does.
 */
interface RevisionInterface
{
    public function revision(): string;
}
