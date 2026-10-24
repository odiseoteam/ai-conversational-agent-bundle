<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

/** The spend cap a turn ran into. */
enum BudgetLimit: string
{
    case Session = 'session';
    case Client = 'client';
    case Day = 'day';
    case Month = 'month';

    /** A cap every visitor shares: reaching it closes the assistant to new visitors too. */
    public function isShared(): bool
    {
        return self::Day === $this || self::Month === $this;
    }
}
