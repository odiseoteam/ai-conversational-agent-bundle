<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Streaming;

/**
 * Best-effort decoding of a tool call's arguments while they are still streaming in.
 *
 * Ported from the reference blueprint's `parse_partial_json`
 * (`commerce_common/streaming.py`): open arrays and objects are closed, a dangling comma or
 * colon is dropped and closing tried again, and a string still being written is left out
 * together with its key, so a status line or a title appears only once it is complete rather
 * than flickering mid-word.
 *
 * This is what lets a status line (`ToolSurface::withStatus` puts it first in the schema) reach
 * the host while the model is still writing the rest of the call's arguments, instead of only
 * once the whole round — every tool call in it — has finished.
 */
final class PartialJson
{
    /** @return array<string, mixed>|null */
    public static function decode(string $buffer): ?array
    {
        $text = trim($buffer);
        if (!str_starts_with($text, '{')) {
            return null;
        }

        $decoded = json_decode($text, true);
        if (\is_array($decoded)) {
            return $decoded;
        }

        [$closing, $inString, $opened] = self::closersFor($text);
        if ($inString) {
            $text = self::beforeOpenString($text, $opened);
            [$closing, $inString] = self::closersFor($text);
        }

        $candidates = [$text.($inString ? '"' : '').$closing];

        $trimmed = rtrim($text);
        while ('' !== $trimmed && \in_array($trimmed[-1], [',', ':'], true)) {
            $trimmed = rtrim(substr($trimmed, 0, -1));
        }
        if ($trimmed !== $text) {
            [$closing2, $inString2] = self::closersFor($trimmed);
            $candidates[] = $trimmed.($inString2 ? '"' : '').$closing2;
        }

        foreach ($candidates as $candidate) {
            $parsed = json_decode($candidate, true);
            if (\is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * The brackets that would close $source if it ended right there, whether it ends inside an
     * open string, and where that string started. Byte-indexed rather than mb_-aware: every
     * structural character JSON cares about (quote, backslash, brace, bracket) is single-byte
     * in UTF-8, and no continuation byte of a multi-byte character can collide with one, so
     * scanning by byte is exact.
     *
     * @return array{0: string, 1: bool, 2: int}
     */
    private static function closersFor(string $source): array
    {
        $stack = [];
        $inString = false;
        $escape = false;
        $opened = -1;

        $length = \strlen($source);
        for ($index = 0; $index < $length; ++$index) {
            $char = $source[$index];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($inString) {
                if ('\\' === $char) {
                    $escape = true;
                } elseif ('"' === $char) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $char) {
                $inString = true;
                $opened = $index;
            } elseif ('{' === $char || '[' === $char) {
                $stack[] = $char;
            } elseif (('}' === $char || ']' === $char) && [] !== $stack) {
                array_pop($stack);
            }
        }

        $closing = '';
        foreach (array_reverse($stack) as $open) {
            $closing .= '[' === $open ? ']' : '}';
        }

        return [$closing, $inString, $opened];
    }

    /**
     * $text cut back to before the string that opens at $opened: over the key and colon when
     * the string is a value, and over the comma that introduced it.
     */
    private static function beforeOpenString(string $text, int $opened): string
    {
        $head = rtrim(substr($text, 0, $opened));
        if (str_ends_with($head, ':')) {
            $head = rtrim(substr($head, 0, -1));
            if (str_ends_with($head, '"')) {
                $index = \strlen($head) - 2;
                while ($index > 0 && !('"' === $head[$index] && '\\' !== $head[$index - 1])) {
                    --$index;
                }
                $head = rtrim(substr($head, 0, $index));
            }
        }
        if (str_ends_with($head, ',')) {
            $head = substr($head, 0, -1);
        }

        return $head;
    }
}
