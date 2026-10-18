<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

/**
 * The part of the deployment the current turn's spend belongs to (one storefront of several,
 * say), so that part can have its own day cap. Read with the session the turn runs in.
 */
interface SpendScopeResolverInterface
{
    /** null when the spend belongs to no scope of its own. */
    public function scope(): ?string;
}
