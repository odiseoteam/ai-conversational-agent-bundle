<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

/** A way to reach the person: an email address or a phone number, nothing else. */
final class Contact
{
    public static function isEmail(string $value): bool
    {
        return false !== filter_var($value, \FILTER_VALIDATE_EMAIL);
    }

    public static function isPhone(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return \strlen($digits) >= 6 && \strlen($digits) <= 20 && 1 === preg_match('/^\+?[\d\s().-]+$/', $value);
    }

    public static function normalize(mixed $raw): ?string
    {
        $value = trim(\is_scalar($raw) ? (string) $raw : '');
        if ('' === $value) {
            return null;
        }
        if (self::isEmail($value)) {
            return mb_strtolower($value);
        }

        return self::isPhone($value) ? $value : null;
    }
}
