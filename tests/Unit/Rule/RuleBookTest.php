<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Rule;

use Odiseo\AiConversationalAgentBundle\Rule\ConfiguredRuleSource;
use Odiseo\AiConversationalAgentBundle\Rule\Rule;
use Odiseo\AiConversationalAgentBundle\Rule\RuleBook;
use Odiseo\AiConversationalAgentBundle\Rule\RuleCategory;
use Odiseo\AiConversationalAgentBundle\Rule\RuleSourceInterface;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\SkippedRulesLogger;
use PHPUnit\Framework\TestCase;

final class RuleBookTest extends TestCase
{
    public function testRulesAreOrderedByCategoryThenAsEachSourceGivesThem(): void
    {
        $book = new RuleBook([
            $this->source(new Rule('upsell', 'Upsell', RuleCategory::Sales, 'Offer the add-ons.'), new Rule('short', 'Short', RuleCategory::Style, 'Be short.')),
            $this->source(new Rule('no-codes', 'No codes', RuleCategory::Sales, 'No discount codes.'), new Rule('plain', 'Plain', RuleCategory::Style, 'Plain words.')),
        ]);

        self::assertSame(['short', 'plain', 'upsell', 'no-codes'], $this->ids($book));
    }

    public function testADisabledRuleIsLeftOutAndDoesNotCountTowardsTheCaps(): void
    {
        $book = new RuleBook([$this->source(
            new Rule('on', 'On', RuleCategory::Other, 'Kept.'),
            new Rule('off', 'Off', RuleCategory::Other, str_repeat('x', 50), enabled: false),
        )], maxRules: 1, maxRuleChars: 10);

        self::assertSame(['on'], $this->ids($book));
    }

    public function testTheConfiguredSourceKeepsTheWrittenOrder(): void
    {
        $source = new ConfiguredRuleSource([
            'b' => ['title' => 'B', 'category' => 'clarification', 'text' => 'Ask the height.', 'enabled' => true],
            'a' => ['title' => 'A', 'category' => 'clarification', 'text' => 'Ask the room.', 'enabled' => true],
        ]);

        self::assertSame(['b', 'a'], $this->ids(new RuleBook([$source])));
    }

    public function testARepeatedIdKeepsTheFirstAndIsLogged(): void
    {
        $logger = new SkippedRulesLogger();
        $book = new RuleBook([
            $this->source(new Rule('short', 'Short', RuleCategory::Style, 'Be short.')),
            $this->source(new Rule('short', 'Short', RuleCategory::Style, 'Be brief.')),
        ], logger: $logger);

        self::assertSame(['Be short.'], array_map(static fn (Rule $rule): string => $rule->text, $book->rules()));
        self::assertSame(['short: its id is already used'], $logger->skipped);
    }

    public function testARuleOverTheLengthCapIsLeftOutAndLogged(): void
    {
        $logger = new SkippedRulesLogger();
        $book = new RuleBook([$this->source(
            new Rule('long', 'Long', RuleCategory::Style, 'Eleven char'),
            new Rule('ok', 'Ok', RuleCategory::Style, 'Ten chars.'),
        )], maxRuleChars: 10, logger: $logger);

        self::assertSame(['ok'], $this->ids($book));
        self::assertSame(['long: it is longer than 10 characters'], $logger->skipped);
    }

    public function testRulesPastTheCountCapAreLeftOutAndLogged(): void
    {
        $logger = new SkippedRulesLogger();
        $book = new RuleBook([$this->source(
            new Rule('other', 'Other', RuleCategory::Other, 'Last by category.'),
            new Rule('style', 'Style', RuleCategory::Style, 'First by category.'),
        )], maxRules: 1, logger: $logger);

        self::assertSame(['style'], $this->ids($book));
        self::assertSame(['other: only 1 rules are used'], $logger->skipped);
    }

    private function source(Rule ...$rules): RuleSourceInterface
    {
        return new class(array_values($rules)) implements RuleSourceInterface {
            /** @param list<Rule> $rules */
            public function __construct(private readonly array $rules)
            {
            }

            public function rules(): iterable
            {
                return $this->rules;
            }
        };
    }

    /** @return list<string> */
    private function ids(RuleBook $book): array
    {
        return array_map(static fn (Rule $rule): string => $rule->id, $book->rules());
    }
}
