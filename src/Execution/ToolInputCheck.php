<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Execution;

/**
 * What in a call's arguments its tool's schema rules out: an argument the schema does not
 * declare where it allows no other, a required one missing, a value outside its enum. A
 * handler reads only what it knows, so without this an argument in the wrong place is dropped
 * in silence. Types are left to the handlers, which coerce them.
 */
final class ToolInputCheck
{
    /**
     * @param array<string, mixed> $schema
     * @param array<mixed>         $input
     *
     * @return list<string>
     */
    public static function problems(array $schema, array $input): array
    {
        return self::object($schema, $input, '', $schema);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<mixed>         $input
     * @param array<string, mixed> $root
     *
     * @return list<string>
     */
    private static function object(array $schema, array $input, string $path, array $root): array
    {
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $problems = [];

        foreach (\is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            if (\is_string($name) && !\array_key_exists($name, $input)) {
                $problems[] = \sprintf('`%s` is required', $path.$name);
            }
        }

        foreach ($input as $name => $value) {
            $name = (string) $name;
            $property = $properties[$name] ?? null;
            if (\is_array($property)) {
                array_push($problems, ...self::value($property, $value, $path.$name, $root));
            } elseif (false === ($schema['additionalProperties'] ?? true)) {
                $problems[] = self::unknown($path, $name, array_keys($properties), $root);
            }
        }

        return $problems;
    }

    /**
     * @param array<mixed>         $schema
     * @param array<string, mixed> $root
     *
     * @return list<string>
     */
    private static function value(array $schema, mixed $value, string $path, array $root): array
    {
        if (\is_array($schema['enum'] ?? null) && \is_scalar($value) && !\in_array($value, $schema['enum'], true)) {
            return [\sprintf('`%s` must be one of: %s', $path, implode(', ', array_map(static fn (mixed $option): string => \is_scalar($option) ? (string) $option : '', $schema['enum'])))];
        }

        if (!\is_array($value)) {
            return [];
        }

        // An empty JSON object decodes to an empty list.
        if ('object' === ($schema['type'] ?? null) && ([] === $value || !array_is_list($value))) {
            /** @var array<string, mixed> $schema */
            return self::object($schema, $value, $path.'.', $root);
        }

        if ('array' === ($schema['type'] ?? null) && array_is_list($value) && \is_array($schema['items'] ?? null)) {
            $problems = [];
            foreach ($value as $index => $item) {
                array_push($problems, ...self::value($schema['items'], $item, \sprintf('%s[%d]', $path, $index), $root));
            }

            return $problems;
        }

        return [];
    }

    /**
     * @param list<int|string>     $allowed
     * @param array<string, mixed> $root
     */
    private static function unknown(string $path, string $name, array $allowed, array $root): string
    {
        $problem = \sprintf('`%s` is not a parameter here (allowed: %s)', $path.$name, [] === $allowed ? 'none' : implode(', ', $allowed));
        $where = self::find($root, $name, '');

        return null === $where ? $problem : \sprintf('%s; it goes in `%s`', $problem, $where);
    }

    /**
     * Where the schema declares an argument of that name, if anywhere.
     *
     * @param array<mixed> $schema
     */
    private static function find(array $schema, string $name, string $path): ?string
    {
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($properties as $key => $property) {
            if (!\is_array($property)) {
                continue;
            }
            if ((string) $key === $name) {
                return $path.$name;
            }
            if (null !== $found = self::find($property, $name, $path.$key.'.')) {
                return $found;
            }
        }

        return null;
    }
}
