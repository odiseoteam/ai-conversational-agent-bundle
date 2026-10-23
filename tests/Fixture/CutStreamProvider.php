<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture;

use Odiseo\AiConversationalAgentBundle\Provider\ModelProviderInterface;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderException;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TextChunk;

/** A stream that writes some text and then stops without a completed turn, or fails. */
final class CutStreamProvider implements ModelProviderInterface
{
    public function __construct(private readonly bool $fails)
    {
    }

    public function capabilities(string $model): ProviderCapabilities
    {
        return new ProviderCapabilities();
    }

    public function stream(TurnRequest $request): iterable
    {
        yield new TextChunk('Looking at the options for you');
        yield new TextChunk(', one moment');

        if ($this->fails) {
            throw new ProviderException('The connection dropped mid-stream.');
        }
    }

    public function complete(TurnRequest $request): ProviderResponse
    {
        throw new ProviderException('CutStreamProvider only streams.');
    }
}
