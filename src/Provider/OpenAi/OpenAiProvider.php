<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\OpenAi;

use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Config\ThinkingEffort;
use Odiseo\AiConversationalAgentBundle\Provider\Platform\PlatformProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiConversationalAgentBundle\Provider\Request\SystemBlock;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * The OpenAI adapter, over the Symfony AI platform bridge and the Responses API.
 *
 * The transcript keeps this product's blocks; here they become Responses items: the system
 * blocks are developer messages, a tool call a `function_call`, its result a
 * `function_call_output`. Caching is automatic, so no marker goes out; `prompt_cache_key` keeps
 * the rounds of a conversation on the same cache. A reasoning model's items are requested
 * encrypted and kept in the transcript, because nothing is stored on OpenAI's side.
 */
final class OpenAiProvider extends PlatformProvider
{
    public function capabilities(string $model): ProviderCapabilities
    {
        $reasoning = self::reasons($model);

        return new ProviderCapabilities(
            streaming: true,
            forcedToolChoice: true,
            thinking: $reasoning,
            parallelToolCalls: true,
            toolInputDeltas: true,
            // A reasoning model rejects a temperature.
            temperature: !$reasoning,
        );
    }

    protected function name(): string
    {
        return 'openai';
    }

    protected function reasoning(DeltaInterface $delta): ?array
    {
        if (!$delta instanceof ThinkingSignature) {
            return null;
        }

        $item = json_decode($delta->getSignature(), true);

        return \is_array($item) ? ['type' => 'reasoning', 'item' => $item] : null;
    }

    /** OpenAI counts cached tokens inside the input; this product counts them apart. */
    protected function usage(?TokenUsageInterface $usage): Usage
    {
        if (null === $usage) {
            return new Usage();
        }

        $cached = $usage->getCachedTokens() ?? 0;

        return new Usage(max(0, ($usage->getPromptTokens() ?? 0) - $cached), $usage->getCompletionTokens() ?? 0, 0, $cached);
    }

    /** The bridge throws on a cut before the usage arrives: the output cap is charged. */
    protected function usageWhenCut(TurnRequest $request): Usage
    {
        return new Usage(0, $request->maxTokens);
    }

    protected function payload(TurnRequest $request): array
    {
        $input = array_map(static fn (SystemBlock $block): array => ['role' => 'developer', 'content' => $block->text], $request->system);
        foreach ($request->messages as $message) {
            array_push($input, ...$this->items($this->ownReasoning($message)));
        }

        $payload = [
            'input' => $input,
            'max_output_tokens' => $request->maxTokens,
            'store' => false,
        ];

        if ([] !== $request->tools) {
            $payload['tools'] = array_map(static fn (ToolSpec $tool): array => [
                'type' => 'function',
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => self::schema($tool->inputSchema),
            ], $request->tools);
            $payload['tool_choice'] = null === $request->toolChoice->tool
                ? $request->toolChoice->type
                : ['type' => 'function', 'name' => $request->toolChoice->tool];
        }

        if (self::reasons($request->model)) {
            $payload['reasoning'] = ['effort' => self::effort($request->thinkingEffort)];
            $payload['include'] = ['reasoning.encrypted_content'];
        } elseif (null !== $request->temperature) {
            $payload['temperature'] = $request->temperature;
        }

        if (null !== $request->cacheKey) {
            $payload['prompt_cache_key'] = $request->cacheKey;
        }

        return $payload;
    }

    /**
     * One transcript message as Responses items, in order: a reasoning item stays before the
     * call it led to.
     *
     * @param array<string, mixed> $message
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $message): array
    {
        $role = Scalar::string($message['role'] ?? null);
        $content = $message['content'] ?? null;
        if (!\is_array($content)) {
            return [['role' => $role, 'content' => Scalar::string($content)]];
        }

        $items = [];
        $text = '';

        foreach ($content as $block) {
            if (!\is_array($block)) {
                continue;
            }
            switch ($block['type'] ?? null) {
                case 'text':
                    $text .= ('' === $text ? '' : "\n").Scalar::string($block['text'] ?? null);
                    break;
                case 'reasoning':
                    self::flush($items, $text, $role);
                    if (\is_array($block['item'] ?? null)) {
                        $items[] = Scalar::keyed($block['item']);
                    }
                    break;
                case 'tool_use':
                    self::flush($items, $text, $role);
                    $items[] = [
                        'type' => 'function_call',
                        'call_id' => Scalar::string($block['id'] ?? null),
                        'name' => Scalar::string($block['name'] ?? null),
                        'arguments' => json_encode($block['input'] ?? [], \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT),
                    ];
                    break;
                case 'tool_result':
                    self::flush($items, $text, $role);
                    $items[] = [
                        'type' => 'function_call_output',
                        'call_id' => Scalar::string($block['tool_use_id'] ?? null),
                        'output' => self::resultText($block['content'] ?? null),
                    ];
                    break;
                default:
                    // Another provider's thinking, untagged from before the tag, cannot go here.
                    break;
            }
        }
        self::flush($items, $text, $role);

        return $items;
    }

    /** @param list<array<string, mixed>> $items */
    private static function flush(array &$items, string &$text, string $role): void
    {
        if ('' !== $text) {
            $items[] = ['role' => $role, 'content' => $text];
            $text = '';
        }
    }

    private static function resultText(mixed $content): string
    {
        if (!\is_array($content)) {
            return Scalar::string($content);
        }

        $parts = [];
        foreach ($content as $part) {
            if (\is_array($part) && 'text' === ($part['type'] ?? null)) {
                $parts[] = Scalar::string($part['text'] ?? null);
            }
        }

        return implode("\n", $parts);
    }

    /** The GPT-5 family and the o-series reason; the rest takes a temperature instead. */
    private static function reasons(string $model): bool
    {
        return 1 === preg_match('/^(gpt-5|o\d)/', $model);
    }

    /** `none` is the lowest; there is nothing above `xhigh`. */
    private static function effort(?ThinkingEffort $effort): string
    {
        return match ($effort) {
            null => 'none',
            ThinkingEffort::Low => 'low',
            ThinkingEffort::Medium => 'medium',
            ThinkingEffort::High => 'high',
            ThinkingEffort::XHigh, ThinkingEffort::Max => 'xhigh',
        };
    }
}
