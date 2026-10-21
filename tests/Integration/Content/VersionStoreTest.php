<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Integration\Content;

use Odiseo\AiConversationalAgentBundle\Content\InMemoryVersionStore;
use Odiseo\AiConversationalAgentBundle\Content\Version;
use Odiseo\AiConversationalAgentBundle\Content\VersionState;
use Odiseo\AiConversationalAgentBundle\Content\VersionStoreInterface;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Orm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionStoreTest extends TestCase
{
    /** @return iterable<string, array{0: \Closure(): VersionStoreInterface}> */
    public static function stores(): iterable
    {
        yield 'memory' => [static fn (): VersionStoreInterface => new InMemoryVersionStore()];
        yield 'orm' => [static fn (): VersionStoreInterface => Orm::versionStore()];
    }

    /** @param \Closure(): VersionStoreInterface $make */
    #[DataProvider('stores')]
    public function testAConfiguredItemStartsAtTwoAndANewOneAtOne(\Closure $make): void
    {
        $store = $make();

        $edited = $store->publish('rule', 'short', ['text' => 'Be short.'], 'ana', 'Shorter', hasDefault: true);
        $created = $store->publish('rule', 'upsell', ['text' => 'Offer add-ons.']);

        self::assertSame(2, $edited->number);
        self::assertSame(1, $created->number);
        self::assertSame(VersionState::Published, $edited->state);
        self::assertSame('ana', $edited->author);
        self::assertSame('Shorter', $edited->note);
    }

    /** @param \Closure(): VersionStoreInterface $make */
    #[DataProvider('stores')]
    public function testPublishedIsTheNewestOfEachItemInKeyOrder(\Closure $make): void
    {
        $store = $make();
        $store->publish('rule', 'b', ['text' => 'B1']);
        $store->publish('rule', 'a', ['text' => 'A1']);
        $store->publish('rule', 'b', ['text' => 'B2']);
        $store->publish('knowledge', 'a', ['body' => 'Other kind']);

        $published = $store->published('rule');

        self::assertSame(['a', 'b'], array_keys($published));
        self::assertSame(['text' => 'B2'], $published['b']->data);
        self::assertSame(2, $published['b']->number);
        self::assertSame([2, 1], array_map(static fn (Version $v): int => $v->number, $store->history('rule', 'b')));
    }

    /** @param \Closure(): VersionStoreInterface $make */
    #[DataProvider('stores')]
    public function testADraftIsKeptApartAndPublishingDropsIt(\Closure $make): void
    {
        $store = $make();
        $store->publish('rule', 'short', ['text' => 'One']);
        $store->saveDraft('rule', 'short', ['text' => 'Draft'], 'ana');
        $store->saveDraft('rule', 'short', ['text' => 'Draft again'], 'ana');

        self::assertSame(['text' => 'One'], $store->published('rule')['short']->data);
        self::assertSame(['text' => 'Draft again'], $store->draft('rule', 'short')?->data);
        self::assertSame(['short'], array_keys($store->drafts('rule')));

        $store->publish('rule', 'short', ['text' => 'Draft again'], 'ana');

        self::assertNull($store->draft('rule', 'short'));
        self::assertSame(2, $store->published('rule')['short']->number);
    }

    /** @param \Closure(): VersionStoreInterface $make */
    #[DataProvider('stores')]
    public function testADraftCanBeDiscarded(\Closure $make): void
    {
        $store = $make();
        $store->saveDraft('rule', 'new', ['text' => 'Draft']);
        $store->discardDraft('rule', 'new');

        self::assertNull($store->draft('rule', 'new'));
        self::assertSame([], $store->published('rule'));
    }

    /** @param \Closure(): VersionStoreInterface $make */
    #[DataProvider('stores')]
    public function testBackToTheDefaultIsAVersionWithoutData(\Closure $make): void
    {
        $store = $make();
        $store->publish('rule', 'short', ['text' => 'Edited'], hasDefault: true);
        $default = $store->publish('rule', 'short', null, hasDefault: true);

        self::assertSame(3, $default->number);
        self::assertTrue($store->published('rule')['short']->isDefault());
    }

    /** @param \Closure(): VersionStoreInterface $make */
    #[DataProvider('stores')]
    public function testTheRevisionChangesOnPublishOnly(\Closure $make): void
    {
        $store = $make();
        $before = $store->revision();

        $store->saveDraft('rule', 'short', ['text' => 'Draft']);
        self::assertSame($before, $store->revision());

        $store->publish('rule', 'short', ['text' => 'Published']);
        self::assertNotSame($before, $store->revision());
    }
}
