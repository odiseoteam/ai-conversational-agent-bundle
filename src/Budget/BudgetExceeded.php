<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Budget;

enum BudgetExceeded: string
{
    case Session = 'session';
    case Day = 'day';
}
