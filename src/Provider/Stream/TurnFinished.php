<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider\Stream;

use Odiseo\AiAgentBundle\Provider\Response\ProviderResponse;

final readonly class TurnFinished implements StreamEvent
{
    public function __construct(public ProviderResponse $response)
    {
    }
}
