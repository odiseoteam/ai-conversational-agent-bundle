<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Rule;

use Odiseo\AiConversationalAgentBundle\Content\InMemoryVersionStore;
use Odiseo\AiConversationalAgentBundle\Rule\ConfiguredRuleSource;
use Odiseo\AiConversationalAgentBundle\Rule\Rule;
use Odiseo\AiConversationalAgentBundle\Rule\RuleBook;
use Odiseo\AiConversationalAgentBundle\Rule\RuleCategory;
use Odiseo\AiConversationalAgentBundle\Rule\VersionedRuleSource;
use PHPUnit\Framework\TestCase;

final class VersionedRuleSourceTest extends TestCase
{
    public function testWithoutVersionsTheConfiguredRulesStand(): void
    {
        self::assertSame(['Be short.', 'Ask the room.'], $this->texts(new InMemoryVersionStore()));
    }

    public function testAPublishedVersionReplacesTheRuleWithItsId(): void
    {
        $store = new InMemoryVersionStore();
        $store->publish('rule', 'short', ['title' => 'Short', 'category' => 'style', 'text' => 'One sentence.', 'enabled' => true], hasDefault: true);

        self::assertSame(['One sentence.', 'Ask the room.'], $this->texts($store));
    }

    public function testBackToTheDefaultLeavesTheConfiguredRule(): void
    {
        $store = new InMemoryVersionStore();
        $store->publish('rule', 'short', ['title' => 'Short', 'category' => 'style', 'text' => 'One sentence.', 'enabled' => true], hasDefault: true);
        $store->publish('rule', 'short', null, hasDefault: true);

        self::assertSame(['Be short.', 'Ask the room.'], $this->texts($store));
    }

    public function testADisabledVersionTurnsTheConfiguredRuleOff(): void
    {
        $store = new InMemoryVersionStore();
        $store->publish('rule', 'room', ['title' => 'Room', 'category' => 'clarification', 'text' => 'Ask the room.', 'enabled' => false], hasDefault: true);

        self::assertSame(['Be short.'], $this->texts($store));
    }

    public function testARuleOnlyInVersionsComesAfterTheConfiguredOnesInIdOrder(): void
    {
        $store = new InMemoryVersionStore();
        $store->publish('rule', 'z-new', ['title' => 'Z', 'category' => 'style', 'text' => 'Z.', 'enabled' => true]);
        $store->publish('rule', 'a-new', ['title' => 'A', 'category' => 'style', 'text' => 'A.', 'enabled' => true]);
        $store->saveDraft('rule', 'drafted', ['title' => 'D', 'category' => 'style', 'text' => 'Draft.', 'enabled' => true]);

        $rules = [...$this->source($store)->rules()];

        self::assertSame(['short', 'room', 'a-new', 'z-new'], array_map(static fn (Rule $rule): string => $rule->id, $rules));
        self::assertSame(RuleCategory::Style, $rules[2]->category);
    }

    public function testTheBookFollowsAPublication(): void
    {
        $store = new InMemoryVersionStore();
        $book = new RuleBook([$this->source($store)], revisions: $store);
        self::assertCount(2, $book->rules());

        $store->publish('rule', 'room', ['title' => 'Room', 'category' => 'clarification', 'text' => 'Ask.', 'enabled' => false], hasDefault: true);

        self::assertCount(1, $book->rules());
    }

    public function testDataRoundTrips(): void
    {
        $rule = new Rule('short', 'Short', RuleCategory::Sales, 'Offer more.', false);

        self::assertEquals($rule, VersionedRuleSource::fromData('short', VersionedRuleSource::toData($rule)));
    }

    private function source(InMemoryVersionStore $store): VersionedRuleSource
    {
        return new VersionedRuleSource(new ConfiguredRuleSource([
            'short' => ['title' => 'Short', 'category' => 'style', 'text' => 'Be short.', 'enabled' => true],
            'room' => ['title' => 'Room', 'category' => 'clarification', 'text' => 'Ask the room.', 'enabled' => true],
        ]), $store);
    }

    /** @return list<string> */
    private function texts(InMemoryVersionStore $store): array
    {
        return array_map(static fn (Rule $rule): string => $rule->text, (new RuleBook([$this->source($store)], revisions: $store))->rules());
    }
}
