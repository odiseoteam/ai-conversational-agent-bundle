<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider;

/** The configured keys only. */
final class NullApiKeyResolver implements ApiKeyResolverInterface
{
    public function apiKey(string $platform): ?string
    {
        return null;
    }
}
