<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider;

/**
 * An API key for a platform other than the deployment's, at runtime: one an administrator
 * saved for a storefront, for example.
 */
interface ApiKeyResolverInterface
{
    /** null keeps the key the platform was configured with. */
    public function apiKey(string $platform): ?string;
}
