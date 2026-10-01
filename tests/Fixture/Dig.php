<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture;

use PHPUnit\Framework\Assert;

/** Walks nested arrays by key in assertions; a missing step fails the test instead of warning. */
final class Dig
{
    public static function value(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            Assert::assertIsArray($value, \sprintf('expected an array before [%s]', $key));
            Assert::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /** @return array<mixed> */
    public static function array(mixed $value, int|string ...$path): array
    {
        $found = self::value($value, ...$path);
        Assert::assertIsArray($found);

        return $found;
    }

    public static function string(mixed $value, int|string ...$path): string
    {
        $found = self::value($value, ...$path);
        Assert::assertIsString($found);

        return $found;
    }
}
