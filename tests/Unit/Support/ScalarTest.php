<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Support;

use Odiseo\AiConversationalAgentBundle\Support\Scalar;
use PHPUnit\Framework\TestCase;

final class ScalarTest extends TestCase
{
    public function testStringMapKeepsTheKeysAndDropsWhatIsNotAString(): void
    {
        self::assertSame(
            ['color' => 'red', '0' => '4', 'size' => '1'],
            Scalar::stringMap(['color' => 'red', 0 => 4, 'size' => true, 'tags' => ['x'], 'none' => null]),
        );
    }

    public function testStringMapOfANonArrayIsEmpty(): void
    {
        self::assertSame([], Scalar::stringMap('red'));
    }
}
