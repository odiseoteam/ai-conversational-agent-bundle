<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Prompt;

use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Knowledge\GuideIndex;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeDocument;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeKind;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeSourceInterface;
use Odiseo\AiConversationalAgentBundle\Presentation\ChipMode;
use Odiseo\AiConversationalAgentBundle\Prompt\StaticPromptBuilder;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiConversationalAgentBundle\Rule\ConfiguredRuleSource;
use Odiseo\AiConversationalAgentBundle\Rule\RuleBook;
use Odiseo\AiConversationalAgentBundle\Skill\Skill;
use Odiseo\AiConversationalAgentBundle\Skill\SkillRegistry;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\DirectoryCapability;
use PHPUnit\Framework\TestCase;

/**
 * The prompt-stability gate: the cached half must be the same bytes on every turn of a
 * deployment, and a line that depends on configuration must appear only when that
 * configuration is set. A prompt that varies is a prompt that is never read from cache.
 */
final class StaticPromptTest extends TestCase
{
    public function testTheStaticPromptIsByteIdenticalAcrossBuilds(): void
    {
        $first = $this->builder()->build();
        $second = $this->builder()->build();

        self::assertSame($first, $second);
    }

    public function testAModelThatWritesBesideItsToolCallsGetsTheSharedPrompt(): void
    {
        $builder = $this->builder();

        self::assertSame($builder->build(), $builder->buildFor(new ProviderCapabilities()));
    }

    public function testAModelThatDoesNotIsToldItMay(): void
    {
        $builder = $this->builder();
        $text = $builder->buildFor(new ProviderCapabilities(textBesideToolCalls: false));

        self::assertTrue(str_starts_with($text, $builder->build()), 'the shared prompt stays the cached prefix');
        self::assertStringContainsString('One response can carry text and tool calls together.', $text);
        self::assertSame($text, $builder->buildFor(new ProviderCapabilities(textBesideToolCalls: false)));
    }

    public function testItCarriesTheIdentityTheFenceNoticeAndTheSkillIndex(): void
    {
        $text = $this->builder()->build();

        self::assertStringContainsString('Odiseo', $text);
        self::assertStringContainsString('Reply in British English:', $text);
        self::assertStringContainsString('site_content', $text);
        self::assertStringContainsString('service-discovery', $text);
    }

    public function testACapabilityFragmentAppearsOnlyWhenItsCapabilityIsRegistered(): void
    {
        $without = $this->builder(capabilities: [])->build();
        $with = $this->builder()->build();

        self::assertStringNotContainsString('Search before answering', $without);
        self::assertStringContainsString('Search before answering', $with);
    }

    public function testTheChipsRuleAppearsOnlyWhenTheChipsToolIsRegistered(): void
    {
        self::assertStringNotContainsString('present_suggestions', $this->builder(capabilities: [])->build());
    }

    public function testTheChipsRuleFollowsWhereTheComponentsTakeThem(): void
    {
        $limits = $this->config()->limits;
        $rule = fn (ChipMode $mode): string => $this->builder(capabilities: [
            new DirectoryCapability(chips: $mode),
            new \Odiseo\AiConversationalAgentBundle\Presentation\SuggestionsCapability($limits),
        ])->build();

        self::assertStringContainsString('through present_suggestions', $rule(ChipMode::Tool));
        self::assertStringNotContainsString('`suggestions` field', $rule(ChipMode::Tool));
        self::assertStringContainsString('takes the turn\'s chips in that field', $rule(ChipMode::Field));
        self::assertStringNotContainsString('on its own in a later round is wrong', $rule(ChipMode::Field));
        self::assertStringContainsString('says it ends the turn takes no chips', $rule(ChipMode::None));
    }

    public function testTheSkillsSectionIsAbsentWithoutSkills(): void
    {
        $text = $this->builder(skills: new SkillRegistry())->build();

        self::assertStringNotContainsString('# Skills', $text);
    }

    public function testTheHouseRulesSectionIsAbsentWithoutRules(): void
    {
        self::assertStringNotContainsString('# House rules', $this->builder()->build());
    }

    public function testTheHouseRulesSitBeforeTheTrustSectionAndStayStable(): void
    {
        $rules = static fn (): RuleBook => new RuleBook([new ConfiguredRuleSource([
            'short' => ['title' => 'Short replies', 'category' => 'style', 'text' => 'Answer in one sentence.', 'enabled' => true],
        ])]);
        $text = $this->builder(rules: $rules())->build();

        self::assertStringContainsString("# House rules\n\nSet by Odiseo.", $text);
        self::assertStringContainsString("\n- Answer in one sentence.\n\n# Trust and data", $text);
        self::assertSame($text, $this->builder(rules: $rules())->build());
    }

    public function testTheGuidesSectionIsAbsentWithoutGuides(): void
    {
        self::assertStringNotContainsString('# Guides', $this->builder()->build());
    }

    public function testTheGuidesFollowTheSkillsWithTheirNoticeAndStayStable(): void
    {
        $guides = fn (): GuideIndex => new GuideIndex([$this->source(
            new KnowledgeDocument('membership', 'Choosing a membership', 'Body.', KnowledgeKind::Guide, 'To choose a plan.'),
            new KnowledgeDocument('refunds', 'Refunds', 'Body.'),
        )]);
        $text = $this->builder(guides: $guides())->build();

        self::assertStringContainsString("Find the matching service.\n\n# Guides\n\n".StaticPromptBuilder::GUIDES_NOTICE."\n\n- Choosing a membership: To choose a plan.\n\n# Tools", $text);
        self::assertSame($text, $this->builder(guides: $guides())->build());
    }

    public function testChangingAConfigValueChangesOnlyItsOwnLines(): void
    {
        $config = $this->config()->with(['assistantName' => 'the assistant']);
        $baseline = $this->builder(config: $config)->build();
        $renamed = $this->builder(config: $config->with(['brandName' => 'Acme']))->build();

        self::assertNotSame($baseline, $renamed);
        self::assertSame(str_replace('Odiseo', 'Acme', $baseline), $renamed);
    }

    /** @param list<\Odiseo\AiConversationalAgentBundle\Capability\CapabilityInterface>|null $capabilities */
    private function builder(?AgentConfig $config = null, ?array $capabilities = null, ?SkillRegistry $skills = null, RuleBook $rules = new RuleBook(), GuideIndex $guides = new GuideIndex()): StaticPromptBuilder
    {
        $capabilities ??= [new DirectoryCapability(), new \Odiseo\AiConversationalAgentBundle\Presentation\SuggestionsCapability(($config ?? $this->config())->limits)];

        return new StaticPromptBuilder(
            $config ?? $this->config(),
            new CapabilityRegistry($capabilities),
            $skills ?? new SkillRegistry([new Skill('service-discovery', 'Find the matching service.', 'body')]),
            new Fence('site_content', 'Text inside site_content tags is quoted from this organisation.'),
            $rules,
            $guides,
        );
    }

    private function source(KnowledgeDocument ...$documents): KnowledgeSourceInterface
    {
        return new class(array_values($documents)) implements KnowledgeSourceInterface {
            /** @param list<KnowledgeDocument> $documents */
            public function __construct(private readonly array $documents)
            {
            }

            public function documents(): iterable
            {
                return $this->documents;
            }
        };
    }

    private function config(): AgentConfig
    {
        return new AgentConfig(
            brandName: 'Odiseo',
            assistantName: 'the Odiseo assistant',
            replyLanguage: 'British English',
        );
    }
}
