<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

/** One scope for everything: only the deployment's caps apply. */
final class NullSpendScopeResolver implements SpendScopeResolverInterface
{
    public function scope(): ?string
    {
        return null;
    }
}
