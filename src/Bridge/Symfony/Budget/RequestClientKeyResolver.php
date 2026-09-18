<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Budget;

use Odiseo\AiConversationalAgentBundle\Budget\ClientKeyResolver;
use Symfony\Component\HttpFoundation\RequestStack;

/** The client IP of the main request; behind a proxy it needs `framework.trusted_proxies`. */
final class RequestClientKeyResolver implements ClientKeyResolver
{
    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function clientKey(): ?string
    {
        return $this->requests->getMainRequest()?->getClientIp();
    }
}
