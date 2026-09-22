<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

/**
 * The last exchanges as a person reads them: what was typed and what was answered, one line
 * each; tool calls and results left out. Long lines are cut, so a pasted page does not travel.
 */
final class TranscriptExcerpt
{
    private const LINE_MAX_CHARS = 600;

    /** @param list<array<string, mixed>> $messages */
    public static function of(array $messages, int $maxMessages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $text = [];
            foreach (\is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                if (\is_array($block) && 'text' === ($block['type'] ?? null) && '' !== trim((string) ($block['text'] ?? ''))) {
                    $text[] = trim((string) $block['text']);
                }
            }
            if ([] === $text) {
                continue;
            }
            $line = implode(' ', $text);
            if (mb_strlen($line) > self::LINE_MAX_CHARS) {
                $line = mb_substr($line, 0, self::LINE_MAX_CHARS - 1).'…';
            }
            $lines[] = ('user' === $role ? 'Customer' : 'Assistant').': '.$line;
        }

        return implode("\n", \array_slice($lines, -$maxMessages));
    }
}
