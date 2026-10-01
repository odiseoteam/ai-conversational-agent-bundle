<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Support;

/** Narrows an untyped value (tool input, stored state, decoded JSON) to a scalar, or a fallback. */
final class Scalar
{
    public static function string(mixed $value, string $default = ''): string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) || \is_bool($value) ? (int) $value : $default;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        $strings = [];
        foreach (\is_array($value) ? $value : [] as $item) {
            if (\is_scalar($item) || $item instanceof \Stringable) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    /** @return array<string, string> */
    public static function stringMap(mixed $value): array
    {
        $map = [];
        foreach (\is_array($value) ? $value : [] as $key => $item) {
            if (\is_scalar($item) || $item instanceof \Stringable) {
                $map[(string) $key] = (string) $item;
            }
        }

        return $map;
    }

    /** @return array<string, mixed> */
    public static function keyed(mixed $value): array
    {
        $keyed = [];
        foreach (\is_array($value) ? $value : [] as $key => $item) {
            $keyed[(string) $key] = $item;
        }

        return $keyed;
    }

    /** @return list<array<string, mixed>> */
    public static function rows(mixed $value): array
    {
        $rows = [];
        foreach (\is_array($value) ? $value : [] as $row) {
            if (\is_array($row)) {
                $rows[] = self::keyed($row);
            }
        }

        return $rows;
    }
}
