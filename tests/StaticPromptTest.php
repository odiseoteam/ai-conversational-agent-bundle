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
        self::assertStringContainsString('Reply in español rioplatense:', $text);
        self::assertStringContainsString('site_content', $text);
        self::assertStringContainsString('service-discovery', $text);
    }

    public function testACapabilityFragmentAppearsOnlyWhenItsCapabilityIsRegistered(): void
    {
        $without = $this->builder(capabilities: [])->build();
        $with = $this->builder()->build();

        self::assertStringNotContainsString('Buscá antes de responder', $without);
        self::assertStringContainsString('Buscá antes de responder', $with);
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
        $config = $this->config()->with(['assistantName' => 'el asistente']);
        $baseline = $this->builder(config: $config)->build();
        $renamed = $this->builder(config: $config->with(['brandName' => 'Otra']))->build();

        self::assertNotSame($baseline, $renamed);
        self::assertSame(str_replace('Odiseo', 'Otra', $baseline), $renamed);
    }

    /** @param list<\Odiseo\AiConversationalAgentBundle\Capability\Capability>|null $capabilities */
    private function builder(?AgentConfig $config = null, ?array $capabilities = null, ?SkillRegistry $skills = null): StaticPromptBuilder
    {
        $capabilities ??= [new DirectoryCapability(), new \Odiseo\AiConversationalAgentBundle\Presentation\SuggestionsCapability(($config ?? $this->config())->limits)];

        return new StaticPromptBuilder(
            $config ?? $this->config(),
            new CapabilityRegistry($capabilities),
            $skills ?? new SkillRegistry([new Skill('service-discovery', 'Encontrar el servicio que corresponde.', 'cuerpo')]),
            new Fence('site_content', 'Text inside site_content tags is quoted from this organisation.'),
        );
    }

    private function config(): AgentConfig
    {
        return new AgentConfig(
            brandName: 'Odiseo',
            assistantName: 'el asistente de Odiseo',
            replyLanguage: 'español rioplatense',
        );
    }
}
