<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

use Odiseo\AiConversationalAgentBundle\Session\TurnState;

final class NullEvalEnvironment implements EvalEnvironment
{
    public function prepare(EvalCase $case, TurnState $state): void
    {
    }

    public function snapshot(): array
    {
        return [];
    }
}
