<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Streaming;

final readonly class AgentEvent
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public EventType $type,
        public array $data = [],
    ) {
    }

    public static function textDelta(string $text): self
    {
        return new self(EventType::TextDelta, ['text' => $text]);
    }

    /** @param array<string, mixed> $input */
    public static function toolCall(string $tool, string $toolUseId, array $input, ?string $label = null): self
    {
        $data = ['tool' => $tool, 'id' => $toolUseId, 'input' => $input];
        if (null !== $label && '' !== $label) {
            $data['label'] = $label;
        }

        return new self(EventType::ToolCall, $data);
    }

    public static function toolResult(
        string $tool,
        string $toolUseId,
        string $summary,
        bool $isError = false,
        ?string $status = null,
        ?string $reason = null,
        ?string $excerpt = null,
    ): self {
        $data = [
            'tool' => $tool,
            'id' => $toolUseId,
            'summary' => $summary,
            'is_error' => $isError,
            'status' => $status ?? ($isError ? 'error' : 'ok'),
        ];
        if (null !== $reason) {
            $data['reason'] = $reason;
        }
        if (null !== $excerpt) {
            $data['excerpt'] = $excerpt;
        }

        return new self(EventType::ToolResult, $data);
    }

    /** @param array<string, mixed> $payload */
    public static function ui(string $component, array $payload): self
    {
        return new self(EventType::Ui, ['component' => $component, 'payload' => $payload]);
    }

    /** The same event tagged with the call that produced it, so the host can replace its partial frames. */
    public function withStreamId(string $streamId): self
    {
        if (EventType::Ui !== $this->type || isset($this->data['stream_id'])) {
            return $this;
        }

        return new self($this->type, $this->data + ['stream_id' => $streamId]);
    }

    /** @param array<string, mixed> $payload */
    public static function uiPartial(string $component, array $payload, string $streamId): self
    {
        return new self(EventType::UiPartial, [
            'component' => $component,
            'payload' => $payload,
            'stream_id' => $streamId,
        ]);
    }

    public static function progress(string $message, ?string $tool = null, ?int $step = null): self
    {
        $data = ['message' => $message];
        if (null !== $tool) {
            $data['tool'] = $tool;
        }
        if (null !== $step) {
            $data['step'] = $step;
        }

        return new self(EventType::Progress, $data);
    }

    /**
     * A whole piece of vertical state after a write moved it, so the host re-renders it
     * without asking. The reference emits cart_update and change_update; this is the same
     * thing with the vertical naming the key.
     */
    public static function stateUpdate(string $key, mixed $value): self
    {
        return new self(EventType::StateUpdate, ['key' => $key, 'value' => $value]);
    }

    /** @param array<string, int> $usage */
    public static function turnComplete(?string $stopReason, array $usage, int $elapsedMs, int $resultsCleared): self
    {
        return new self(EventType::TurnComplete, [
            'stop_reason' => $stopReason,
            'usage' => $usage,
            'elapsed_ms' => $elapsedMs,
            'results_cleared' => $resultsCleared,
        ]);
    }

    /** $code is what a host translates on; $message is a neutral English fallback, safe to show as is. */
    public static function error(string $code, string $message): self
    {
        return new self(EventType::Error, ['code' => $code, 'message' => $message]);
    }
}
