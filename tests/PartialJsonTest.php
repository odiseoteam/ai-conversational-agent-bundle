<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Streaming\PartialJson;
use PHPUnit\Framework\TestCase;

final class PartialJsonTest extends TestCase
{
    public function testNonObjectTextDecodesToNull(): void
    {
        self::assertNull(PartialJson::decode(''));
        self::assertNull(PartialJson::decode('  '));
        self::assertNull(PartialJson::decode('[1, 2]'));
        self::assertNull(PartialJson::decode('not json at all'));
    }

    public function testACompleteObjectDecodesDirectly(): void
    {
        self::assertSame(['status' => 'Buscando'], PartialJson::decode('{"status": "Buscando"}'));
    }

    public function testAStatusThatFinishedBeforeLaterFieldsStartIsAvailable(): void
    {
        $decoded = PartialJson::decode('{"status": "Buscando servicios de Sylius", "query": "syl');

        self::assertSame('Buscando servicios de Sylius', $decoded['status'] ?? null);
        self::assertArrayNotHasKey('query', $decoded, 'a value still being written is left out with its key');
    }

    public function testAStatusStillMidWordIsNotYetAvailable(): void
    {
        // The whole pair backs out (key included, per beforeOpenString), leaving a bare {} —
        // still a valid decode, just with nothing usable in it yet.
        $decoded = PartialJson::decode('{"status": "Busc');

        self::assertArrayNotHasKey('status', $decoded ?? []);
    }

    public function testATrailingCommaIsDroppedAndClosingRetried(): void
    {
        $decoded = PartialJson::decode('{"status": "Buscando",');

        self::assertSame(['status' => 'Buscando'], $decoded);
    }

    public function testATrailingKeyWithNoColonYetFailsToDecode(): void
    {
        // A known gap the reference algorithm itself has: once the dangling colon is dropped,
        // what remains ends in a bare key with no colon before the closing brace, which is not
        // valid JSON either, so this one chunk decodes to null. It costs nothing in practice —
        // the status was already extracted from an earlier chunk before "query" started.
        self::assertNull(PartialJson::decode('{"status": "Buscando", "query":'));
    }

    public function testNestedObjectsAreClosedInOrder(): void
    {
        $decoded = PartialJson::decode('{"status": "Filtrando", "filters": {"attributes": {"type": "service"');

        self::assertSame('Filtrando', $decoded['status'] ?? null);
        self::assertSame(['type' => 'service'], $decoded['filters']['attributes'] ?? null);
    }

    public function testAnEscapedQuoteInsideTheOpenStringIsNotMistakenForItsEnd(): void
    {
        $decoded = PartialJson::decode('{"status": "Buscando \\"marketplace\\" ahora", "query": "unf');

        self::assertSame('Buscando "marketplace" ahora', $decoded['status'] ?? null);
        self::assertArrayNotHasKey('query', $decoded);
    }

    public function testAnArrayKeepsItsCompleteElementsAndDropsTheOneStillWriting(): void
    {
        $decoded = PartialJson::decode('{"status": "Presentando", "suggestions": ["Uno", "Dos');

        self::assertSame('Presentando', $decoded['status'] ?? null);
        self::assertSame(['Uno'], $decoded['suggestions'] ?? null);
    }
}
