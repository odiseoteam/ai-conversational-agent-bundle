<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

/**
 * Runs one case so that nothing it writes outlives it: the session, the memory, the spend and
 * whatever the host's tools wrote. A case starts from what the store holds, never from the
 * case before it, and its spend does not count against the day's budget.
 */
interface EvalIsolation
{
    /**
     * @template T
     *
     * @param \Closure(): T $case
     *
     * @return T
     */
    public function isolate(\Closure $case): mixed;
}
