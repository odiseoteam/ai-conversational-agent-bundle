<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\Response;

/**
 * One completed model call: the assistant message as content blocks the transcript stores, the
 * tool calls it asked for, why it stopped, and what it consumed.
 */
final readonly class ProviderResponse
{
    /**
     * @param list<array<string, mixed>> $content
     * @param list<ToolUse>              $toolUses
     */
    public function __construct(
        public array $content,
        public array $toolUses = [],
        public ?string $stopReason = null,
        public Usage $usage = new Usage(),
    ) {
    }

    public function text(): string
    {
        $text = '';
        foreach ($this->content as $block) {
            if ('text' === ($block['type'] ?? null)) {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return $text;
    }

    /** @return array<string, mixed>|null the assistant message to append to the transcript */
    public function assistantMessage(): ?array
    {
        return [] === $this->content ? null : ['role' => 'assistant', 'content' => $this->content];
    }
}
