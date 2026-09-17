<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Execution;

use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;

/**
 * A capability that has exceptions of its own maps them here, so the model is told what is
 * actually the matter ("that is not something we do", "ask them to sign in first") instead of
 * the generic "temporarily unavailable" the failure ladder ends in.
 */
interface DomainErrorMapper
{
    public function mapError(\Throwable $error, ExecutorWording $wording): ?ToolOutcome;
}
