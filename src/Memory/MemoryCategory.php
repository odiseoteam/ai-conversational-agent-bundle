<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Memory;

enum MemoryCategory: string
{
    /** A leaning: what they tend to want. Injected when there is room. */
    case Preference = 'preference';
    /** A hard limit: a budget, an allergy, a date. Injected on every turn. */
    case Constraint = 'constraint';
    /** A fact about their situation that explains the rest. */
    case Context = 'context';
}
