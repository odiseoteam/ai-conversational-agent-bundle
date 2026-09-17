<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Fencing;

/**
 * The tag that wraps third-party content and the notice the static prompt carries about it.
 *
 * A vertical defines one Fence. Its label is a source literal, never built from runtime
 * values, so untrusted text cannot reproduce the boundary.
 */
final readonly class Fence
{
    public function __construct(
        public string $label,
        public string $notice,
    ) {
    }

    public function open(): string
    {
        return '<'.$this->label.'>';
    }

    public function close(): string
    {
        return '</'.$this->label.'>';
    }

    /**
     * $maxChars bounds the result including the truncation suffix, so a schema limit can be
     * passed as is.
     */
    public function sanitizeText(string $text, ?int $maxChars = null): string
    {
        $text = Sanitizer::normalize($text);
        $text = Sanitizer::stripInvisible($text);
        $text = Sanitizer::replaceControls($text);

        // Markers and tokens are removed to a fixpoint, so one nested inside another
        // ("</label</label>>") does not reassemble after the inner one goes.
        $marker = Sanitizer::markerPattern($this->label);
        while (true) {
            $stripped = Sanitizer::stripSpecialTokens((string) preg_replace($marker, '[removed]', $text));
            if ($stripped === $text) {
                break;
            }
            $text = $stripped;
        }

        $text = Sanitizer::defuseTurnIndicators($text);

        if (null !== $maxChars && mb_strlen($text) > $maxChars) {
            $suffix = ' ...[truncated]';
            $text = $maxChars > mb_strlen($suffix)
                ? mb_substr($text, 0, $maxChars - mb_strlen($suffix)).$suffix
                : mb_substr($text, 0, $maxChars);
        }

        return $text;
    }

    /**
     * Sanitizes every string leaf of a value, keys included.
     */
    public function sanitizeValue(mixed $value, ?int $maxChars = null): mixed
    {
        if (\is_string($value)) {
            return $this->sanitizeText($value, $maxChars);
        }

        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $key = \is_string($key) ? $this->sanitizeText($key, 200) : $key;
                $out[$key] = $this->sanitizeValue($item, $maxChars);
            }

            return $out;
        }

        if ($value instanceof \JsonSerializable) {
            return $this->sanitizeValue($value->jsonSerialize(), $maxChars);
        }

        if ($value instanceof \Stringable) {
            return $this->sanitizeText((string) $value, $maxChars);
        }

        return $value;
    }

    /**
     * The sanitized payload inside the fence. String leaves are sanitized in place; any other
     * object is sanitized as it is stringified, so a __toString cannot carry a marker in.
     */
    public function fencePayload(mixed $payload, int $maxChars = Sanitizer::MAX_FENCED_CHARS): string
    {
        $sanitized = $this->sanitizeValue($payload);

        $body = \is_string($sanitized)
            ? $sanitized
            : (string) json_encode($sanitized, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (mb_strlen($body) > $maxChars) {
            $body = mb_substr($body, 0, $maxChars).' ...[truncated]';
        }

        // The fence's own newline would complete a blank line the in-body pattern cannot
        // see, so a marker at the very start is defused at wrap time.
        $body = Sanitizer::defuseLeadingTurnIndicator($body);

        return $this->open()."\n".$body."\n".$this->close();
    }
}
