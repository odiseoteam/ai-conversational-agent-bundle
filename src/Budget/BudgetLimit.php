<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

/** The spend cap a turn ran into. */
enum BudgetLimit: string
{
    case Session = 'session';
    case Client = 'client';
    case Day = 'day';
}
