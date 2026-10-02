<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval\Grader;

use Odiseo\AiConversationalAgentBundle\Eval\EvalCase;
use Odiseo\AiConversationalAgentBundle\Eval\TurnRecording;

/**
 * Checks a recorded case against the expected keys it owns. Graders are tagged, so a vertical
 * adds the keys of its own state (a cart, a ledger) without the core knowing them; an expected
 * key no grader owns fails the case instead of passing unchecked.
 */
interface Grader
{
    /** @return list<string> */
    public function keys(): array;

    /** @return list<string> one line per expectation that did not hold */
    public function grade(EvalCase $case, TurnRecording $recording): array;
}
