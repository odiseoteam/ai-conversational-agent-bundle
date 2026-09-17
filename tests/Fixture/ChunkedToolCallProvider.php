<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture;

use Odiseo\AiConversationalAgentBundle\Provider\ModelProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderException;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ToolUse;
use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TextChunk;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\ToolCallStarted;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\ToolInputChunk;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TurnFinished;

/**
 * A provider that streams tool calls' arguments piece by piece, the way a real one does — for
 * what FakeProvider does not simulate: a status line or a partial frame arriving well before
 * the call finishes, and a call closing while the model still writes what follows it.
 *
 * Each call is [tool, id, chunks, finalInput]; $trailingText streams after the last call and
 * before the turn finishes. A second round answers with $closing.
 */
final class ChunkedToolCallProvider implements ModelProvider
{
    private int $round = 0;

    /**
     * @param list<array{0: string, 1: string, 2: list<string>, 3: array<string, mixed>}> $calls
     */
    public function __construct(
        private readonly array $calls,
        private readonly string $trailingText = '',
        private readonly string $closing = 'Listo.',
    ) {
    }

    /**
     * @param list<string>         $chunks
     * @param array<string, mixed> $finalInput
     */
    public static function single(string $tool, string $id, array $chunks, array $finalInput, string $trailingText = ''): self
    {
        return new self([[$tool, $id, $chunks, $finalInput]], $trailingText);
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(forcedToolChoice: true);
    }

    public function stream(TurnRequest $request): iterable
    {
        if ($this->round++ > 0) {
            yield new TextChunk($this->closing);
            yield new TurnFinished(new ProviderResponse([['type' => 'text', 'text' => $this->closing]], [], 'end_turn', new Usage(10, 5)));

            return;
        }

        $content = [];
        $uses = [];
        foreach ($this->calls as [$tool, $id, $chunks, $finalInput]) {
            yield new ToolCallStarted($id, $tool);
            foreach ($chunks as $chunk) {
                yield new ToolInputChunk($id, $tool, $chunk);
            }
            $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $finalInput];
            $uses[] = new ToolUse($id, $tool, $finalInput);
        }

        if ('' !== $this->trailingText) {
            yield new TextChunk($this->trailingText);
            $content[] = ['type' => 'text', 'text' => $this->trailingText];
        }

        yield new TurnFinished(new ProviderResponse($content, $uses, 'tool_use', new Usage(10, 5)));
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
