<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\Platform;

use Odiseo\AiConversationalAgentBundle\Provider\AuthenticationException;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderException;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiConversationalAgentBundle\Provider\Response\StopReason;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ToolUse;
use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TextChunk;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\ToolCallStarted;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\ToolInputChunk;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TurnFinished;
use Symfony\AI\Platform\Exception\AuthenticationException as PlatformAuthenticationException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * What every adapter over a Symfony AI platform shares: the stream, read the same way for any
 * bridge because they all emit the same deltas, the error mapping, and the usage and stop
 * reason in this product's terms. An adapter writes its provider's payload and says which
 * deltas carry reasoning.
 *
 * Reasoning blocks are tagged with the adapter's name in the transcript. An adapter sends back
 * its own, without the tag, and drops another provider's, so a conversation can change provider
 * halfway; a block with no tag predates the tag and is sent as it is.
 *
 * A call cut at the output limit is not an error: the bridges throw on it, and here it becomes
 * a response with what arrived, `StopReason::MaxTokens` and the usage reported so far, so the
 * call is charged.
 */
abstract class PlatformProvider implements ModelProvider
{
    public function __construct(private readonly PlatformInterface $platform)
    {
    }

    /** The tag on this provider's reasoning blocks. */
    abstract protected function name(): string;

    /** @return array<string, mixed> the request body as the provider takes it */
    abstract protected function payload(TurnRequest $request): array;

    /** @return array<string, mixed>|null the block a reasoning delta adds to the transcript */
    abstract protected function reasoning(DeltaInterface $delta): ?array;

    public function stream(TurnRequest $request): iterable
    {
        $content = [];
        $text = '';
        $toolUses = [];
        $truncated = false;

        // Usage and the finish reason never reach this loop as deltas: the platform's stream
        // listeners fold them into the deferred result's metadata, read once the stream is
        // drained. asStream() returns a generator that has not run yet: the HTTP call and the
        // SSE parsing happen as this loop iterates it, so the try wraps the iteration.
        $deferred = $this->invoke($request);

        try {
            foreach ($deferred->asStream() as $event) {
                if ($event instanceof TextDelta) {
                    $text .= $event->getText();
                    yield new TextChunk($event->getText());
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
                        // A plain array, not (object): the bridge's Contract runs the payload
                        // through the Symfony Serializer, which has no normalizer for stdClass.
                        $content[] = ['type' => 'tool_use', 'id' => $call->getId(), 'name' => $call->getName(), 'input' => $call->getArguments()];
                        $toolUses[] = new ToolUse($call->getId(), $call->getName(), $call->getArguments());
                    }
                    continue;
                }

                if ($event instanceof DeltaInterface && null !== $block = $this->reasoning($event)) {
                    // Reasoning travels back with the assistant message: a turn that drops it
                    // cannot continue a tool call under extended thinking.
                    self::flushText($content, $text);
                    $content[] = ['type' => $block['type'] ?? 'thinking', 'provider' => $this->name()] + $block;
                }
            }
        } catch (MaxOutputTokensException) {
            $truncated = true;
        } catch (PlatformAuthenticationException $failed) {
            throw new AuthenticationException($failed->getMessage(), 0, $failed);
        } catch (PlatformException $failed) {
            throw new ProviderException($failed->getMessage(), 0, $failed);
        }

        self::flushText($content, $text);

        $metadata = $deferred->getMetadata();
        $tokenUsage = $metadata->get('token_usage');
        $finishReason = $metadata->get('finish_reason');

        yield new TurnFinished(new ProviderResponse(
            $content,
            $toolUses,
            $truncated ? StopReason::MaxTokens : self::stopReason($finishReason instanceof FinishReason ? $finishReason : null),
            $truncated && !$tokenUsage instanceof TokenUsageInterface
                ? $this->usageWhenCut($request)
                : $this->usage($tokenUsage instanceof TokenUsageInterface ? $tokenUsage : null),
        ));
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

    /** Input is what was neither written to nor read from the cache, as Anthropic counts it. */
    protected function usage(?TokenUsageInterface $usage): Usage
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

    /**
     * What a call cut at the output limit is charged when the bridge reports no usage for it.
     * Nothing by default: a bridge that reports it never gets here.
     */
    protected function usageWhenCut(TurnRequest $request): Usage
    {
        return new Usage();
    }

    /**
     * The messages as this provider takes them back: its own reasoning without the tag, and
     * another provider's left out.
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    protected function ownReasoning(array $message): array
    {
        $content = $message['content'] ?? null;
        if (!\is_array($content)) {
            return $message;
        }

        $kept = [];
        foreach ($content as $block) {
            if (\is_array($block) && \array_key_exists('provider', $block)) {
                if ($this->name() !== $block['provider']) {
                    continue;
                }
                unset($block['provider']);
            }
            $kept[] = $block;
        }
        $message['content'] = $kept;

        return $message;
    }

    /**
     * A JSON schema as every provider takes it: an empty `properties` is left out, because some
     * reject `[]` and the serializer cannot write `{}`.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    protected static function schema(array $schema): array
    {
        if ([] === ($schema['properties'] ?? null)) {
            unset($schema['properties']);
        }

        return $schema;
    }

    private static function stopReason(?FinishReason $reason): ?StopReason
    {
        return match ($reason?->getCase()) {
            null => null,
            FinishReasonCase::STOP => StopReason::EndTurn,
            FinishReasonCase::TOOL_CALL => StopReason::ToolUse,
            FinishReasonCase::LENGTH => StopReason::MaxTokens,
            FinishReasonCase::CONTENT_FILTER => StopReason::Refusal,
            FinishReasonCase::STOP_SEQUENCE => StopReason::StopSequence,
            FinishReasonCase::OTHER => StopReason::Other,
        };
    }

    /** @param list<array<string, mixed>> $content */
    private static function flushText(array &$content, string &$text): void
    {
        if ('' !== $text) {
            $content[] = ['type' => 'text', 'text' => $text];
            $text = '';
        }
    }

    /**
     * The synchronous half of a call: resolving the model and starting the request. What the
     * platform raises while streaming is mapped in stream(), because asStream() returns unstarted.
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
}
