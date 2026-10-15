<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Rule;

/** Groups the rules; the prompt lists them in this order. */
enum RuleCategory: string
{
    case Style = 'style';
    case Sales = 'sales';
    case Clarification = 'clarification';
    case Other = 'other';
}
