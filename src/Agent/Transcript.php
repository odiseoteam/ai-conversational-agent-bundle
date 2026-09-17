<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Agent;

use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;

/**
 * The stored conversation: what goes in it, and the repairs a turn may owe it.
 *
 * A transcript that ends on a tool call with no result is rejected by the provider on the next
 * request, so a turn the host abandons mid-round still has to settle its open calls.
 */
final class Transcript
{
    /** @return array<string, mixed> */
    public static function userMessage(string $text): array
    {
        return ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * The user's message, preceded by a note listing what happened outside the conversation
     * since the last reply, when anything did.
     *
     * @param list<string> $appEvents
     *
     * @return array<string, mixed>
     */
    public static function userTurn(string $text, array $appEvents, string $eventsLabel): array
    {
        if ([] === $appEvents) {
            return self::userMessage($text);
        }

        $note = \sprintf('[%s since your last reply: %s]', $eventsLabel, implode(' ', $appEvents));

        return ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $note],
            ['type' => 'text', 'text' => $text],
        ]];
    }

    /** @return array<string, mixed> */
    public static function toolResultBlock(string $toolUseId, ToolOutcome $outcome): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => $outcome->resultText,
            'is_error' => $outcome->isError,
        ];
    }

    /** @param list<array<string, mixed>> $messages */
    public static function latestUserText(array $messages): string
    {
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            if ('user' !== ($messages[$i]['role'] ?? null)) {
                continue;
            }

            $text = '';
            foreach (\is_array($messages[$i]['content'] ?? null) ? $messages[$i]['content'] : [] as $block) {
                if (\is_array($block) && 'text' === ($block['type'] ?? null)) {
                    $text .= (string) ($block['text'] ?? '')."\n";
                }
            }

            if ('' !== trim($text)) {
                return trim($text);
            }
        }

        return '';
    }

    /**
     * Pair any tool call that has no result with one, so the stored conversation is always
     * sendable.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, ToolOutcome> $settled
     */
    public static function closeOpenToolUses(array &$messages, array $settled): void
    {
        $open = [];
        foreach ($messages as $message) {
            if ('assistant' === ($message['role'] ?? null)) {
                foreach (\is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                    if (\is_array($block) && 'tool_use' === ($block['type'] ?? null)) {
                        $open[(string) ($block['id'] ?? '')] = true;
                    }
                }
                continue;
            }

            foreach (\is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                if (\is_array($block) && 'tool_result' === ($block['type'] ?? null)) {
                    unset($open[(string) ($block['tool_use_id'] ?? '')]);
                }
            }
        }

        if ([] === $open) {
            return;
        }

        $blocks = [];
        foreach (array_keys($open) as $toolUseId) {
            $outcome = $settled[$toolUseId] ?? ToolOutcome::error('The call did not complete.');
            $blocks[] = self::toolResultBlock($toolUseId, $outcome);
        }

        $messages[] = ['role' => 'user', 'content' => $blocks];
    }

    /**
     * Clear the oldest tool results once the prompt has grown past the cap, and report how
     * many were cleared: a host that appends its transcript rewrites it when this is nonzero.
     *
     * @param list<array<string, mixed>> $messages
     */
    public static function compact(array &$messages, int $promptTokens, int $threshold): int
    {
        if ($threshold <= 0 || $promptTokens < $threshold) {
            return 0;
        }

        $cleared = 0;
        // The last exchange is what the next turn reasons from, so compaction stops short of it.
        $limit = max(0, \count($messages) - 4);
        for ($i = 0; $i < $limit; ++$i) {
            $content = $messages[$i]['content'] ?? null;
            if (!\is_array($content)) {
                continue;
            }

            foreach ($content as $index => $block) {
                if (\is_array($block) && 'tool_result' === ($block['type'] ?? null)
                    && '[cleared]' !== ($block['content'] ?? null)) {
                    $messages[$i]['content'][$index]['content'] = '[cleared]';
                    ++$cleared;
                }
            }
        }

        return $cleared;
    }

    /** @param list<array<string, mixed>> $messages */
    public static function text(array $messages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            foreach (\is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                if (\is_array($block) && 'text' === ($block['type'] ?? null)) {
                    $lines[] = $role.': '.(string) ($block['text'] ?? '');
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The last exchange: the newest message the user typed and everything after it. Tool
     * results travel as user-role messages too, so a message counts only when it carries text.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    public static function latestExchange(array $messages): array
    {
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            if ('user' === ($messages[$i]['role'] ?? null) && self::hasText($messages[$i])) {
                return \array_slice($messages, $i);
            }
        }

        return $messages;
    }

    /** @param array<string, mixed> $message */
    private static function hasText(array $message): bool
    {
        foreach (\is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null) && '' !== trim((string) ($block['text'] ?? ''))) {
                return true;
            }
        }

        return false;
    }
}
