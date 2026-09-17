<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider\Fake;

use Odiseo\AiAgentBundle\Provider\ModelProvider;
use Odiseo\AiAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiAgentBundle\Provider\ProviderException;
use Odiseo\AiAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiAgentBundle\Provider\Response\ToolUse;
use Odiseo\AiAgentBundle\Provider\Response\Usage;
use Odiseo\AiAgentBundle\Provider\Stream\TextChunk;
use Odiseo\AiAgentBundle\Provider\Stream\ToolCallStarted;
use Odiseo\AiAgentBundle\Provider\Stream\TurnFinished;

/**
 * A scripted model, for tests and for eval replays. It answers with the responses it was
 * given, in order, and records every request so a test can assert on the prompt bytes.
 */
final class FakeProvider implements ModelProvider
{
    /** @var list<ProviderResponse> */
    private array $responses;

    /** @var list<TurnRequest> */
    private array $requests = [];

    /** @param list<ProviderResponse> $responses */
    public function __construct(array $responses = [], private readonly ProviderCapabilities $capabilities = new ProviderCapabilities(
        streaming: true,
        promptCaching: true,
        serverTools: false,
        forcedToolChoice: true,
        thinking: true,
    ))
    {
        $this->responses = $responses;
    }

    public static function text(string $text, string $stopReason = 'end_turn'): ProviderResponse
    {
        return new ProviderResponse(
            [['type' => 'text', 'text' => $text]],
            stopReason: $stopReason,
            usage: new Usage(10, 5),
        );
    }

    /** @param array<string, mixed> $input */
    public static function toolCall(string $tool, array $input, string $id = 'tu-1', string $text = ''): ProviderResponse
    {
        $content = '' === $text ? [] : [['type' => 'text', 'text' => $text]];
        $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input];

        return new ProviderResponse(
            $content,
            [new ToolUse($id, $tool, $input)],
            'tool_use',
            new Usage(10, 5),
        );
    }

    public function capabilities(): ProviderCapabilities
    {
        return $this->capabilities;
    }

    public function stream(TurnRequest $request): iterable
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses);
        if (null === $response) {
            throw new ProviderException('FakeProvider ran out of scripted responses.');
        }

        foreach ($response->content as $block) {
            if ('text' === ($block['type'] ?? null)) {
                yield new TextChunk((string) ($block['text'] ?? ''));
            }
        }

        foreach ($response->toolUses as $call) {
            yield new ToolCallStarted($call->id, $call->name);
        }

        yield new TurnFinished($response);
    }

    public function complete(TurnRequest $request): ProviderResponse
    {
        foreach ($this->stream($request) as $event) {
            if ($event instanceof TurnFinished) {
                return $event->response;
            }
        }

        throw new ProviderException('FakeProvider produced no completed turn.');
    }

    /** @return list<TurnRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?TurnRequest
    {
        return [] === $this->requests ? null : $this->requests[\count($this->requests) - 1];
    }
}
