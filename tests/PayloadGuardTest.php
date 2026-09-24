<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Presentation\PayloadGuard;
use Odiseo\AiConversationalAgentBundle\Presentation\PresentationRefused;
use PHPUnit\Framework\TestCase;

/** Every string the model sends to a card is bounded, and a card never renders empty. */
final class PayloadGuardTest extends TestCase
{
    public function testTextIsTrimmedCutAndRequired(): void
    {
        self::assertSame('abc', PayloadGuard::text('  abcdef ', 3, 'title'));

        $this->expectException(\InvalidArgumentException::class);
        PayloadGuard::text(['not', 'text'], 3, 'title');
    }

    public function testOptionalTextTurnsBlankIntoNull(): void
    {
        self::assertNull(PayloadGuard::optionalText('   ', 10));
        self::assertSame('ok', PayloadGuard::optionalText(' ok ', 10));
    }

    public function testListsDropBlanksAndKeepTheirCap(): void
    {
        self::assertSame(['a', 'b'], PayloadGuard::textList(['a', '', null, 'b', 'c'], 2, 5));
        self::assertSame([], PayloadGuard::textList('a', 2, 5));
    }

    public function testRowsNeedTheirMinimum(): void
    {
        self::assertCount(2, PayloadGuard::rows([['a' => 1], 'x', ['b' => 2], ['c' => 3]], 'entries', 2));

        $this->expectException(\InvalidArgumentException::class);
        PayloadGuard::rows([['a' => 1]], 'entries', 4, 2);
    }

    public function testACardWithNothingLeftIsRefused(): void
    {
        $this->expectException(PresentationRefused::class);
        PayloadGuard::requireAny([], 'comparison');
    }
}
