<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\Stream;

final readonly class TextChunk implements StreamEventInterface
{
    public function __construct(public string $text)
    {
    }
}
