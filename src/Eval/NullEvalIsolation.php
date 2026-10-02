<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

/** Without a database there is nothing to undo: each case already gets its own session and memory subject. */
final class NullEvalIsolation implements EvalIsolation
{
    public function isolate(\Closure $case): mixed
    {
        return $case();
    }
}
