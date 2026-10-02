<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

use Odiseo\AiConversationalAgentBundle\Session\TurnState;

/**
 * What the host does around a case: builds the preconditions the core cannot (a cart, a signed-in
 * customer, an eval-only listing) and reads back the state the case ended in, so graders and a
 * replay see it without the host.
 */
interface EvalEnvironment
{
    /**
     * Runs inside the case's isolation, after the session starts and before the first turn. The
     * state is the session's, for the host to remember the records its own precondition keys
     * name (the products a case says were already seen).
     */
    public function prepare(EvalCase $case, TurnState $state): void;

    /** @return array<string, mixed> */
    public function snapshot(): array;
}
