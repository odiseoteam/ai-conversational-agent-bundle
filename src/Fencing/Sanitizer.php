<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Fencing;

/**
 * Text hygiene for anything the model reads as data or writes for a person to read.
 *
 * Every pattern here is linear on hostile input: it runs on the request path, before
 * truncation.
 */
final class Sanitizer
{
    public const MAX_FENCED_CHARS = 12_000;

    /** Enforced at payload validation rather than in the tool schema, which is cache-frozen. */
    public const SUGGESTION_CHIP_MAX_CHARS = 80;

    /** Zero-width, bidi and format controls: the usual carriers for hidden instructions. */
    private const INVISIBLE = '/[\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{206A}-\x{206F}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFF9}-\x{FFFB}\x{E0000}-\x{E007F}\x{E0100}-\x{E01EF}]/u';

    /** C0/C1 controls except tab and newline. */
    private const CONTROL = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u';

    /**
     * A forged turn boundary: a blank line, then a full role word and a colon. Mid-sentence
     * role words, single-newline headings and one-letter list markers ("A:") do not match.
     */
    private const TURN_INDICATOR = '/((?:\r\n|\r|\n)[ \t]*(?:\r\n|\r|\n)[ \t]*)(human|assistant|system|user)[ \t]*:/iu';

    private const LEADING_TURN_INDICATOR = '/^(\s*)(human|assistant|system|user)[ \t]*:/iu';

    private const TAG_ATTRS = '(?:[ \t]+[\w:.-]{1,40}[ \t]*=[ \t]*(?:"[^"]{0,200}"|\'[^\']{0,200}\'|[^\s"\'>]{1,200})){0,8}';

    private const WHITESPACE_RUN = '/\s+/u';

    /** @var array<string, string> */
    private static array $markerPatterns = [];

    private static ?string $specialToken = null;

    public static function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (\is_string($normalized)) {
                return $normalized;
            }
        }

        return $text;
    }

    public static function stripInvisible(string $text): string
    {
        return (string) preg_replace(self::INVISIBLE, '', $text);
    }

    public static function replaceControls(string $text): string
    {
        return (string) preg_replace(self::CONTROL, ' ', $text);
    }

    public static function defuseTurnIndicators(string $text): string
    {
        return (string) preg_replace(self::TURN_INDICATOR, '$1$2 -', $text);
    }

    public static function defuseLeadingTurnIndicator(string $text): string
    {
        return (string) preg_replace(self::LEADING_TURN_INDICATOR, '$1$2 -', $text);
    }

    /**
     * A marker is the label after an opening bracket, with or without the slash, spaces,
     * attributes, or the closing bracket ("</label x=\"\">", "< /label>", "</label").
     */
    public static function markerPattern(string $label): string
    {
        return self::$markerPatterns[$label] ??= '/<\s*\/?\s*'.preg_quote($label, '/').'(?![A-Za-z0-9_])(?:[^<>]*>)?/iu';
    }

    /**
     * Transcript and tool-call markup, optionally namespaced. Only tag-shaped text matches, so
     * "<system requirements>" passes; `parameter` and `result` count only when namespaced.
     */
    public static function stripSpecialTokens(string $text): string
    {
        self::$specialToken ??= '/<[ \t]*\/?[ \t]*(?:'
            .'(?:[a-z][\w.-]{0,30}:)?(?:transcript|conversation|function_calls|function_results'
            .'|invoke|tool_use|tool_result|system|human|user|assistant)'
            .'|[a-z][\w.-]{0,30}:(?:parameter|result)'
            .')\b'.self::TAG_ATTRS.'[ \t]*\/?>'
            .'|<\|[^|<>\r\n]{1,64}\|>/iu';

        return (string) preg_replace(self::$specialToken, '[removed]', $text);
    }

    /**
     * Model text shown to a person as one line (a chip, a status line): invisible and control
     * characters out, whitespace collapsed, cut to $maxChars with an ellipsis; empty when
     * nothing visible is left.
     */
    public static function label(mixed $text, int $maxChars): string
    {
        $line = self::stripInvisible(\is_scalar($text) || $text instanceof \Stringable ? (string) $text : '');
        $line = self::replaceControls($line);
        $line = trim((string) preg_replace(self::WHITESPACE_RUN, ' ', $line));

        if (mb_strlen($line) > $maxChars) {
            $line = rtrim(mb_substr($line, 0, $maxChars - 1)).'…';
        }

        return $line;
    }

    /**
     * Chips as one-line button labels: each through label(), empty ones dropped, at most $max.
     *
     * @param iterable<mixed> $chips
     *
     * @return list<string>
     */
    public static function suggestionChips(iterable $chips, int $max = 4, int $maxChars = self::SUGGESTION_CHIP_MAX_CHARS): array
    {
        $cleaned = [];
        foreach ($chips as $chip) {
            if ('' !== $label = self::label($chip, $maxChars)) {
                $cleaned[] = $label;
            }
            if (\count($cleaned) === $max) {
                break;
            }
        }

        return $cleaned;
    }

    /** Text shown to a person, cut at a word boundary with an ellipsis. */
    public static function truncateDisplay(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxChars - 1);
        if (false !== $space = mb_strrpos($cut, ' ')) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,;:-—–').'…';
    }
}
