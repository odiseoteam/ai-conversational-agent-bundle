<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\Platform;

use Symfony\AI\Platform\PlatformInterface;

/**
 * Builds a platform like the configured one with another key: the same factory and the same
 * arguments after the key (HTTP client, catalog, contract, cache settings).
 */
final class PlatformFactory
{
    /**
     * @param callable    $factory   the configured platform's factory, taking the key first
     * @param list<mixed> $arguments what it takes after the key
     */
    public function __construct(
        private readonly mixed $factory,
        private readonly array $arguments = [],
    ) {
    }

    public function create(#[\SensitiveParameter] string $apiKey): PlatformInterface
    {
        $platform = \call_user_func($this->factory, $apiKey, ...$this->arguments);
        if (!$platform instanceof PlatformInterface) {
            throw new \LogicException(\sprintf('The platform factory returned %s, not a platform.', get_debug_type($platform)));
        }

        return $platform;
    }
}
