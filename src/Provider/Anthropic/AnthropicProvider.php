<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\Anthropic;

use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Provider\Platform\PlatformProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiConversationalAgentBundle\Provider\Request\SystemBlock;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;

/**
 * The Anthropic adapter, over the Symfony AI platform bridge.
 *
 * The payload is written here, not by the bridge: this product places its own cache
 * breakpoints, so the host configures the platform with `cache_retention: none` and the markers
 * below are the only ones on the request; with the default the bridge adds its own.
 */
final class AnthropicProvider extends PlatformProvider
{
    public function capabilities(string $model): ProviderCapabilities
    {
        return new ProviderCapabilities(
            streaming: true,
            promptCaching: true,
            serverTools: true,
            // Sonnet 5.5 rejects a forced tool choice.
            forcedToolChoice: !str_starts_with($model, 'claude-sonnet-5-5'),
            // Haiku 4.5 has no adaptive thinking: it rejects the effort the turn asks for.
            thinking: !str_starts_with($model, 'claude-haiku-4-5'),
            usageAccounting: true,
            parallelToolCalls: true,
            toolInputDeltas: true,
            // The Claude 5 models reject a temperature.
            temperature: 1 !== preg_match('/^claude-(fable|opus|sonnet|haiku)-5/', $model),
            // These check that the history before a replayed thinking block was not edited.
            editableHistory: 1 !== preg_match('/^claude-(fable-5-1|opus-5-5|sonnet-5-5|haiku-5-5)/', $model),
        );
    }

    protected function name(): string
    {
        return 'anthropic';
    }

    protected function reasoning(DeltaInterface $delta): ?array
    {
        if (!$delta instanceof ThinkingComplete) {
            return null;
        }

        return array_filter([
            'type' => 'thinking',
            'thinking' => $delta->getThinking(),
            'signature' => $delta->getSignature(),
        ], static fn (mixed $value): bool => null !== $value);
    }

    protected function payload(TurnRequest $request): array
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
            // Sonnet 5.5 rejects `disabled`: thinking only between tool calls is its lowest setting.
            $payload['thinking'] = ['type' => str_starts_with($request->model, 'claude-sonnet-5-5') ? 'between_tools' : 'disabled'];
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
        $message = $this->ownReasoning($message);
        $content = $message['content'] ?? null;
        if (!\is_array($content)) {
            return $message;
        }

        foreach ($content as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ($block['cache_hint'] ?? false) {
                $block['cache_control'] = ['type' => 'ephemeral'];
            }
            unset($block['cache_hint']);
            $content[$index] = $block;
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
            static fn (ToolSpec $tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => self::schema($tool->inputSchema),
            ],
            $tools,
        );

        if ($cacheTools && [] !== $mapped) {
            $mapped[\count($mapped) - 1]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $mapped;
    }
}
