<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\Stream;

use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;

final readonly class TurnFinished implements StreamEvent
{
    public function __construct(public ProviderResponse $response)
    {
    }
}
