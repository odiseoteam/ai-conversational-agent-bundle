<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Session;

use Odiseo\AiConversationalAgentBundle\Session\Provenance;
use Odiseo\AiConversationalAgentBundle\Session\SeenRecord;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use PHPUnit\Framework\TestCase;

/** Numeric ids become int array keys in PHP; the state must still hand them back as strings. */
final class TurnStateTest extends TestCase
{
    public function testLookingUpAnUnseenIdNextToANumericOneDoesNotFail(): void
    {
        $state = new TurnState();
        $state->remember(new SeenRecord('56710', 'product'), new SeenRecord('ab_cd', 'product'));

        self::assertSame(['56710', 'ab_cd'], $state->seenIds());
        self::assertNull(Provenance::find($state, '1154726215'));
        self::assertSame('ab_cd', Provenance::find($state, 'AB_CD')?->id);
    }

    public function testNumericIdsSurviveTheCap(): void
    {
        $state = new TurnState();
        for ($i = 1; $i <= 201; ++$i) {
            $state->remember(new SeenRecord((string) (50000 + $i), 'product'));
        }

        self::assertCount(200, $state->seenIds());
        self::assertFalse($state->hasSeen('50001'));
        self::assertTrue($state->hasSeen('50002'));
        self::assertTrue($state->hasSeen('50201'));
        self::assertSame('50201', $state->seen('50201')?->id);
    }
}
