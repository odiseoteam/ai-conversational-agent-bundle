<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Prompt\StaticPromptBuilder;
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

    public function testTheSkillsSectionIsAbsentWithoutSkills(): void
    {
        $text = $this->builder(skills: new SkillRegistry())->build();

        self::assertStringNotContainsString('# Skills', $text);
    }

    public function testChangingAConfigValueChangesOnlyItsOwnLines(): void
    {
        $config = $this->config()->with(['assistantName' => 'the assistant']);
        $baseline = $this->builder(config: $config)->build();
        $renamed = $this->builder(config: $config->with(['brandName' => 'Acme']))->build();

        self::assertNotSame($baseline, $renamed);
        self::assertSame(str_replace('Odiseo', 'Acme', $baseline), $renamed);
    }

    /** @param list<\Odiseo\AiConversationalAgentBundle\Capability\Capability>|null $capabilities */
    private function builder(?AgentConfig $config = null, ?array $capabilities = null, ?SkillRegistry $skills = null): StaticPromptBuilder
    {
        $capabilities ??= [new DirectoryCapability(), new \Odiseo\AiConversationalAgentBundle\Presentation\SuggestionsCapability(($config ?? $this->config())->limits)];

        return new StaticPromptBuilder(
            $config ?? $this->config(),
            new CapabilityRegistry($capabilities),
            $skills ?? new SkillRegistry([new Skill('service-discovery', 'Find the matching service.', 'body')]),
            new Fence('site_content', 'Text inside site_content tags is quoted from this organisation.'),
        );
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
