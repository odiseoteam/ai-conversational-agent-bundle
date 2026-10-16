<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Knowledge;

enum KnowledgeKind: string
{
    case Policy = 'policy';
    case Guide = 'guide';
    case Snippet = 'snippet';
}
