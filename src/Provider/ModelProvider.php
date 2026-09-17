<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider;

use Odiseo\AiAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiAgentBundle\Provider\Stream\StreamEvent;

/**
 * A model behind one adapter. The interface is defined by what this product needs — streaming,
 * prompt caching, forced reads, server-side tools, usage accounting — not by the shape of any
 * one vendor's API, so a second adapter is a mapping rather than a translation.
 */
interface ModelProvider
{
    public function capabilities(): ProviderCapabilities;

    /**
     * One streamed model call. The last event is always TurnFinished.
     *
     * @return iterable<StreamEvent>
     *
     * @throws ProviderException
     */
    public function stream(TurnRequest $request): iterable;

    /**
     * One buffered model call, for work nobody is watching: memory extraction, an eval judge,
     * a delegate.
     *
     * @throws ProviderException
     */
    public function complete(TurnRequest $request): ProviderResponse;
}
