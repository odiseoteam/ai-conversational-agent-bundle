<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Memory\InMemoryMemoryStore;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryCategory;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryFact;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryStore;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Orm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The contract every memory store keeps, run against the in-memory one and the ORM one. */
final class MemoryStoreTest extends TestCase
{
    /** @return iterable<string, array{0: \Closure(): MemoryStore}> */
    public static function stores(): iterable
    {
        yield 'memory' => [static fn (): MemoryStore => new InMemoryMemoryStore()];
        yield 'orm' => [static fn (): MemoryStore => Orm::memoryStore()];
    }

    /** @param \Closure(): MemoryStore $make */
    #[DataProvider('stores')]
    public function testFactsComeBackNewestFirstAndPerSubject(\Closure $make): void
    {
        $store = $make();
        $store->save('s1', new MemoryFact('size', '42', MemoryCategory::Preference, new \DateTimeImmutable('2026-09-01')));
        $store->save('s1', new MemoryFact('colour', 'blue', MemoryCategory::Preference, new \DateTimeImmutable('2026-09-02')));
        $store->save('s2', new MemoryFact('size', '38', MemoryCategory::Preference, new \DateTimeImmutable('2026-09-03')));

        self::assertSame(['colour', 'size'], array_map(static fn (MemoryFact $f): string => $f->key, $store->all('s1')));
        self::assertSame(['size'], array_map(static fn (MemoryFact $f): string => $f->key, $store->all('s2')));
    }

    /** @param \Closure(): MemoryStore $make */
    #[DataProvider('stores')]
    public function testASaveUnderAKnownKeyReplacesTheValue(\Closure $make): void
    {
        $store = $make();
        $store->save('s1', new MemoryFact('size', '42', sourceSessionTag: 'a'));
        $store->save('s1', new MemoryFact('size', '44', MemoryCategory::Constraint, sourceSessionTag: 'b'));

        $facts = $store->all('s1');
        self::assertCount(1, $facts);
        self::assertSame('44', $facts[0]->value);
        self::assertSame(MemoryCategory::Constraint, $facts[0]->category);
        self::assertSame('b', $facts[0]->sourceSessionTag);
    }

    /** @param \Closure(): MemoryStore $make */
    #[DataProvider('stores')]
    public function testSearchMatchesAnyWordInKeyOrValueIgnoringCase(\Closure $make): void
    {
        $store = $make();
        $store->save('s1', new MemoryFact('shoe_size', '42', updatedAt: new \DateTimeImmutable('2026-09-01')));
        $store->save('s1', new MemoryFact('colour', 'Navy blue', updatedAt: new \DateTimeImmutable('2026-09-02')));
        $store->save('s1', new MemoryFact('kids', 'two, 4 and 7', updatedAt: new \DateTimeImmutable('2026-09-03')));

        self::assertSame(['colour'], array_map(static fn (MemoryFact $f): string => $f->key, $store->search('s1', 'BLUE')));
        self::assertSame(['kids', 'shoe_size'], array_map(static fn (MemoryFact $f): string => $f->key, $store->search('s1', 'size kids')));
        self::assertSame([], $store->search('s1', 'nothing'));
        self::assertCount(2, $store->search('s1', '', 2));
    }

    /** @param \Closure(): MemoryStore $make */
    #[DataProvider('stores')]
    public function testForgetAndClear(\Closure $make): void
    {
        $store = $make();
        $store->save('s1', new MemoryFact('size', '42'));
        $store->save('s1', new MemoryFact('colour', 'blue'));
        $store->save('s2', new MemoryFact('size', '38'));

        self::assertTrue($store->forget('s1', 'size'));
        self::assertFalse($store->forget('s1', 'size'));
        self::assertCount(1, $store->all('s1'));

        $store->clear('s1');
        self::assertSame([], $store->all('s1'));
        self::assertCount(1, $store->all('s2'));
    }
}
