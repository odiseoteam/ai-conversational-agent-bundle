<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider\Anthropic;

use Odiseo\AiAgentBundle\Capability\ToolSpec;
use Odiseo\AiAgentBundle\Provider\AuthenticationException;
use Odiseo\AiAgentBundle\Provider\ModelProvider;
use Odiseo\AiAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiAgentBundle\Provider\ProviderException;
use Odiseo\AiAgentBundle\Provider\Request\SystemBlock;
use Odiseo\AiAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiAgentBundle\Provider\Response\ToolUse;
use Odiseo\AiAgentBundle\Provider\Response\Usage;
use Odiseo\AiAgentBundle\Provider\Stream\TextChunk;
use Odiseo\AiAgentBundle\Provider\Stream\ToolCallStarted;
use Odiseo\AiAgentBundle\Provider\Stream\ToolInputChunk;
use Odiseo\AiAgentBundle\Provider\Stream\TurnFinished;
use Symfony\AI\Platform\Exception\AuthenticationException as PlatformAuthenticationException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * The Anthropic adapter, over the Symfony AI platform bridge.
 *
 * The payload is written here, not by the bridge: this product places its own cache
 * breakpoints, so the bridge's model client is configured with cache retention "none" and the
 * markers below are the only ones on the request. What the bridge does supply is the HTTP
 * plumbing, the SSE parsing, the error mapping and the usage extraction.
 */
final class AnthropicProvider implements ModelProvider
{
    public function __construct(private readonly PlatformInterface $platform)
    {
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            streaming: true,
            promptCaching: true,
            serverTools: true,
            forcedToolChoice: true,
            thinking: true,
            usageAccounting: true,
            parallelToolCalls: true,
        );
    }

    public function stream(TurnRequest $request): iterable
    {
        $content = [];
        $text = '';
        $toolUses = [];

        // Usage and the finish reason never reach this loop as deltas: the platform's own
        // TokenUsageStreamListener and MetaDataStreamListener intercept those two delta types
        // on the way through (Symfony\AI\Platform\Result\StreamResult::consume() marks them
        // "skipped") and fold them into the deferred result's metadata instead, precisely so
        // that a caller who only wants the text never has to filter them out. They are read
        // from that metadata below, once the stream has been drained.
        //
        // asStream() itself returns a generator that has not run yet: the HTTP call and the SSE
        // parsing happen lazily, as this loop iterates it, so the try/catch has to wrap the
        // iteration itself rather than the call that hands back the generator.
        $deferred = $this->invoke($request);

        try {
            foreach ($deferred->asStream() as $event) {
                if ($event instanceof TextDelta) {
                    $text .= $event->getText();
                    yield new TextChunk($event->getText());
                    continue;
                }

                if ($event instanceof ThinkingComplete) {
                    // Thinking blocks travel back with the assistant message: a turn that
                    // drops them cannot continue a tool call under extended thinking.
                    self::flushText($content, $text);
                    $content[] = array_filter([
                        'type' => 'thinking',
                        'thinking' => $event->getThinking(),
                        'signature' => $event->getSignature(),
                    ], static fn (mixed $value): bool => null !== $value);
                    continue;
                }

                if ($event instanceof ToolCallStart) {
                    yield new ToolCallStarted($event->getId(), $event->getName());
                    continue;
                }

                if ($event instanceof ToolInputDelta) {
                    yield new ToolInputChunk($event->getId(), $event->getName(), $event->getPartialJson());
                    continue;
                }

                if ($event instanceof ToolCallComplete) {
                    self::flushText($content, $text);
                    foreach ($event->getToolCalls() as $call) {
                        $content[] = [
                            'type' => 'tool_use',
                            'id' => $call->getId(),
                            'name' => $call->getName(),
                            // A plain array, not (object): the bridge's Contract runs this
                            // whole payload through the Symfony Serializer before it reaches
                            // JSON, and that serializer has no normalizer for stdClass. An
                            // empty array becomes `[]` rather than Anthropic's `{}` for a
                            // zero-argument call; every tool this deployment registers requires
                            // at least one property, so the case does not arise yet.
                            'input' => $call->getArguments(),
                        ];
                        $toolUses[] = new ToolUse($call->getId(), $call->getName(), $call->getArguments());
                    }
                }
            }
        } catch (PlatformAuthenticationException $failed) {
            throw new AuthenticationException($failed->getMessage(), 0, $failed);
        } catch (PlatformException $failed) {
            throw new ProviderException($failed->getMessage(), 0, $failed);
        }

        self::flushText($content, $text);

        $metadata = $deferred->getMetadata();
        $usage = $this->usageFrom($metadata->get('token_usage'));
        $finishReason = $metadata->get('finish_reason');
        $stopReason = null === $finishReason ? null : (string) $finishReason;

        yield new TurnFinished(new ProviderResponse($content, $toolUses, $stopReason, $usage));
    }

    private function usageFrom(?TokenUsageInterface $usage): Usage
    {
        if (null === $usage) {
            return new Usage();
        }

        return new Usage(
            $usage->getPromptTokens() ?? 0,
            $usage->getCompletionTokens() ?? 0,
            $usage->getCacheCreationTokens() ?? 0,
            $usage->getCacheReadTokens() ?? 0,
        );
    }

    public function complete(TurnRequest $request): ProviderResponse
    {
        foreach ($this->stream($request) as $event) {
            if ($event instanceof TurnFinished) {
                return $event->response;
            }
        }

        throw new ProviderException('The model stream ended without a completed turn.');
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private static function flushText(array &$content, string &$text): void
    {
        if ('' !== $text) {
            $content[] = ['type' => 'text', 'text' => $text];
            $text = '';
        }
    }

    /**
     * The synchronous half of one call: resolving the model and starting the request. Anything
     * the platform raises while resolving the model (an unknown model id) is mapped here;
     * anything it raises while streaming the response is mapped in stream() instead, because
     * asStream() there returns unstarted.
     */
    private function invoke(TurnRequest $request): DeferredResult
    {
        if ('' === $request->model) {
            throw new ProviderException('The request names no model.');
        }

        try {
            return $this->platform->invoke($request->model, $this->payload($request), ['stream' => true]);
        } catch (PlatformAuthenticationException $failed) {
            throw new AuthenticationException($failed->getMessage(), 0, $failed);
        } catch (PlatformException $failed) {
            throw new ProviderException($failed->getMessage(), 0, $failed);
        }
    }

    /** @return array<string, mixed> */
    private function payload(TurnRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'system' => array_map(
                static fn (SystemBlock $block): array => $block->cacheHint
                    ? ['type' => 'text', 'text' => $block->text, 'cache_control' => ['type' => 'ephemeral']]
                    : ['type' => 'text', 'text' => $block->text],
                $request->system,
            ),
            'messages' => array_map($this->message(...), $request->messages),
        ];

        if ([] !== $request->tools) {
            $payload['tools'] = $this->tools($request->tools, $request->cacheTools);
            $payload['tool_choice'] = null === $request->toolChoice->tool
                ? ['type' => $request->toolChoice->type]
                : ['type' => 'tool', 'name' => $request->toolChoice->tool];
        }

        if (null !== $request->temperature) {
            $payload['temperature'] = $request->temperature;
        }

        if (null === $request->thinkingEffort) {
            $payload['thinking'] = ['type' => 'disabled'];
        } else {
            $payload['thinking'] = ['type' => 'adaptive'];
            $payload['output_config'] = ['effort' => $request->thinkingEffort->value];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function message(array $message): array
    {
        $content = $message['content'] ?? null;
        if (!\is_array($content)) {
            return $message;
        }

        foreach ($content as $index => $block) {
            if (\is_array($block) && ($block['cache_hint'] ?? false)) {
                unset($content[$index]['cache_hint']);
                $content[$index]['cache_control'] = ['type' => 'ephemeral'];
            } elseif (\is_array($block)) {
                unset($content[$index]['cache_hint']);
            }
        }

        $message['content'] = array_values($content);

        return $message;
    }

    /**
     * @param list<ToolSpec> $tools
     *
     * @return list<array<string, mixed>>
     */
    private function tools(array $tools, bool $cacheTools): array
    {
        $mapped = array_map(
            static fn (ToolSpec $tool): array => $tool->providerDefinition ?? [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->inputSchema,
            ],
            $tools,
        );

        if ($cacheTools && [] !== $mapped) {
            $mapped[\count($mapped) - 1]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $mapped;
    }
}
