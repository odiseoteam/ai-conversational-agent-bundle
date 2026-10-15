<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

final class NullClientKeyResolver implements ClientKeyResolverInterface
{
    public function clientKey(): ?string
    {
        return null;
    }
}
