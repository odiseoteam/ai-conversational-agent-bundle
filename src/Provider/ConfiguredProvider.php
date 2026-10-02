<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider;

use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;

/**
 * A provider with the deployment's `capabilities` laid over what its adapter knows, for a model
 * the adapter does not know yet. Calls go through untouched.
 */
final class ConfiguredProvider implements ModelProvider
{
    /** @param array<string, array<string, bool>> $overrides by model, then by capability */
    public function __construct(
        private readonly ModelProvider $provider,
        private readonly array $overrides,
    ) {
    }

    public function capabilities(string $model): ProviderCapabilities
    {
        return $this->provider->capabilities($model)->with($this->overrides[$model] ?? []);
    }

    public function stream(TurnRequest $request): iterable
    {
        return $this->provider->stream($request);
    }

    public function complete(TurnRequest $request): ProviderResponse
    {
        return $this->provider->complete($request);
    }
}
