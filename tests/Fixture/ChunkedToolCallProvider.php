<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Tests\Fixture;

use Odiseo\AiAgentBundle\Provider\ModelProvider;
use Odiseo\AiAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiAgentBundle\Provider\ProviderException;
use Odiseo\AiAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiAgentBundle\Provider\Response\ToolUse;
use Odiseo\AiAgentBundle\Provider\Response\Usage;
use Odiseo\AiAgentBundle\Provider\Stream\ToolCallStarted;
use Odiseo\AiAgentBundle\Provider\Stream\ToolInputChunk;
use Odiseo\AiAgentBundle\Provider\Stream\TurnFinished;

/**
 * A provider that streams one tool call's arguments piece by piece, the way a real one does —
 * for the one thing FakeProvider does not simulate: the status line arriving well before the
 * argument that follows it finishes.
 */
final class ChunkedToolCallProvider implements ModelProvider
{
    /**
     * @param list<string>         $chunks     the tool call's JSON input, split however the test wants
     * @param array<string, mixed> $finalInput
     */
    public function __construct(
        private readonly string $tool,
        private readonly string $id,
        private readonly array $chunks,
        private readonly array $finalInput,
    ) {
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(forcedToolChoice: true);
    }

    public function stream(TurnRequest $request): iterable
    {
        yield new ToolCallStarted($this->id, $this->tool);
        foreach ($this->chunks as $chunk) {
            yield new ToolInputChunk($this->id, $this->tool, $chunk);
        }

        yield new TurnFinished(new ProviderResponse(
            [['type' => 'tool_use', 'id' => $this->id, 'name' => $this->tool, 'input' => $this->finalInput]],
            [new ToolUse($this->id, $this->tool, $this->finalInput)],
            'tool_use',
            new Usage(10, 5),
        ));
    }

    public function complete(TurnRequest $request): ProviderResponse
    {
        foreach ($this->stream($request) as $event) {
            if ($event instanceof TurnFinished) {
                return $event->response;
            }
        }

        throw new ProviderException('ChunkedToolCallProvider produced no completed turn.');
    }
}
