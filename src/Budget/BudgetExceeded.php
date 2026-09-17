<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

enum BudgetExceeded: string
{
    case Session = 'session';
    case Day = 'day';
}
