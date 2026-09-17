<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Grounding;

/**
 * The lexicon matchers a grounding rule is written with. The lexicons themselves are
 * per-client configuration, in the client's language; this class only matches.
 */
final class Matcher
{
    private const MONEY_LITERAL = '/[$€]\s?\d/u';
    private const PERCENT_LITERAL = '/\d+\s?%/u';

    /**
     * Case-insensitive whole-word (or whole-phrase) match; "?" matches literally.
     *
     * @param iterable<string> $needles
     */
    public static function matchesAny(string $text, iterable $needles): bool
    {
        $lowered = mb_strtolower($text);

        foreach ($needles as $needle) {
            $cleaned = trim(mb_strtolower($needle));
            if ('' === $cleaned) {
                continue;
            }

            if ('?' === $cleaned) {
                if (str_contains($lowered, '?')) {
                    return true;
                }
                continue;
            }

            // \b is byte-oriented on accented words, so the boundary is spelled out with
            // lookarounds over the unicode letter class instead.
            $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($cleaned, '/').'(?![\p{L}\p{N}_])/iu';
            if (1 === preg_match($pattern, $lowered)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the text carries a term and a cue. With $numericLiterals a money or percent
     * figure also counts as a term. Empty text or an empty lexicon never fires.
     *
     * @param iterable<string> $terms
     * @param iterable<string> $cues
     */
    public static function matchesTermsAndCues(string $text, iterable $terms, iterable $cues, bool $numericLiterals = false): bool
    {
        $terms = \is_array($terms) ? $terms : iterator_to_array($terms, false);
        $cues = \is_array($cues) ? $cues : iterator_to_array($cues, false);

        if ('' === $text || [] === $terms || [] === $cues || !self::matchesAny($text, $cues)) {
            return false;
        }

        if (self::matchesAny($text, $terms)) {
            return true;
        }

        return $numericLiterals
            && (1 === preg_match(self::MONEY_LITERAL, $text) || 1 === preg_match(self::PERCENT_LITERAL, $text));
    }

    /**
     * The longest match of any pattern in the text (case-insensitive), or null.
     *
     * @param iterable<string> $patterns
     */
    public static function findToken(string $text, iterable $patterns): ?string
    {
        if ('' === $text) {
            return null;
        }

        $token = null;
        foreach ($patterns as $pattern) {
            if (1 === preg_match('/'.str_replace('/', '\/', $pattern).'/iu', $text, $matches)
                && (null === $token || mb_strlen($matches[0]) > mb_strlen($token))) {
                $token = $matches[0];
            }
        }

        return $token;
    }
}
