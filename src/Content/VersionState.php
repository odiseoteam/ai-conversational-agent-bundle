<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Content;

enum VersionState: string
{
    /** Saved, not in use: the agent never reads it. */
    case Draft = 'draft';

    /** In use from its publication until a newer one. */
    case Published = 'published';
}
