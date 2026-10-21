<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\Platform;

use Odiseo\AiConversationalAgentBundle\Provider\ApiKeyResolverInterface;
use Odiseo\AiConversationalAgentBundle\Provider\NullApiKeyResolver;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/**
 * A platform that calls with the key the host resolves at runtime, or with the configured
 * platform when there is none. A platform per key is built once and kept, with the configured
 * one's options (its HTTP client, catalog and cache settings).
 */
final class KeyedPlatform implements PlatformInterface
{
    /** @var array<string, PlatformInterface> */
    private array $byKey = [];

    /** @param PlatformFactory|null $factory null when the configured platform cannot be rebuilt */
    public function __construct(
        private readonly PlatformInterface $configured,
        private readonly string $name,
        private readonly ?PlatformFactory $factory = null,
        private readonly ApiKeyResolverInterface $keys = new NullApiKeyResolver(),
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        return $this->platform()->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->configured->getModelCatalog();
    }

    private function platform(): PlatformInterface
    {
        $key = null === $this->factory ? null : $this->keys->apiKey($this->name);
        if (null === $key || '' === $key || null === $this->factory) {
            return $this->configured;
        }

        return $this->byKey[hash('sha256', $key)] ??= $this->factory->create($key);
    }
}
