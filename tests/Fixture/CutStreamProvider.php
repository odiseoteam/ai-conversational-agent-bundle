<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture;

use Odiseo\AiConversationalAgentBundle\Provider\ModelProviderInterface;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderException;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TextChunk;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TurnFinished;

/**
 * A stream that writes some text and then stops without a completed turn, or fails. $writes
 * false fails before streaming anything; $before are rounds answered in full first.
 */
final class CutStreamProvider implements ModelProviderInterface
{
    /** @param list<ProviderResponse> $before */
    public function __construct(
        private readonly bool $fails,
        private readonly bool $writes = true,
        private readonly bool $caching = false,
        private array $before = [],
    ) {
    }

    public function capabilities(string $model): ProviderCapabilities
    {
        return new ProviderCapabilities(promptCaching: $this->caching);
    }

    public function stream(TurnRequest $request): iterable
    {
        $answered = array_shift($this->before);
        if (null !== $answered) {
            yield new TurnFinished($answered);

            return;
        }

        if ($this->writes) {
            yield new TextChunk('Looking at the options for you');
            yield new TextChunk(', one moment');
        }

        if ($this->fails) {
            throw new ProviderException('The connection dropped mid-stream.');
        }
    }

    public function complete(TurnRequest $request): ProviderResponse
    {
        throw new ProviderException('CutStreamProvider only streams.');
    }
}
