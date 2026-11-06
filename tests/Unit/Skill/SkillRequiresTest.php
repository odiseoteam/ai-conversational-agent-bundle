<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Skill;

use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Capability\Limits;
use Odiseo\AiConversationalAgentBundle\Capability\ToolContext;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use Odiseo\AiConversationalAgentBundle\Skill\Skill;
use Odiseo\AiConversationalAgentBundle\Skill\SkillCapability;
use Odiseo\AiConversationalAgentBundle\Skill\SkillLoadException;
use Odiseo\AiConversationalAgentBundle\Skill\SkillRegistry;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\DirectoryCapability;
use PHPUnit\Framework\TestCase;

final class SkillRequiresTest extends TestCase
{
    private ?CapabilityRegistry $registry = null;

    public function testTheFrontmatterDeclaresTheTools(): void
    {
        $skill = SkillRegistry::parse("---\nname: refunds\ndescription: Refund flow.\nrequires: [issue_refund]\n---\nRules.");

        self::assertSame(['issue_refund'], $skill->requires);
    }

    public function testASkillRequiresNothingByDefault(): void
    {
        self::assertSame([], SkillRegistry::parse("---\nname: refunds\ndescription: Refund flow.\n---\nRules.")->requires);
    }

    public function testRequiresIsAList(): void
    {
        $this->expectException(SkillLoadException::class);

        SkillRegistry::parse("---\nname: refunds\ndescription: Refund flow.\nrequires: issue_refund\n---\nRules.");
    }

    public function testASkillNeedsEveryToolItRequires(): void
    {
        $skill = new Skill('refunds', 'Refund flow.', 'Rules.', ['issue_refund', 'find_records']);

        self::assertFalse($skill->availableWith(['find_records']));
        self::assertTrue($skill->availableWith(['find_records', 'issue_refund', 'other']));
    }

    public function testASkillWhoseToolIsMissingCannotBeLoaded(): void
    {
        $capability = $this->capability();

        self::assertTrue($capability->execute(SkillCapability::TOOL, ['skill_name' => 'refunds'], self::context())->isError);
        self::assertSame('Rules.', $capability->execute(SkillCapability::TOOL, ['skill_name' => 'records'], self::context())->resultText);
    }

    public function testTheToolListsOnlyWhatCanBeLoaded(): void
    {
        self::assertStringContainsString('"enum":["records"]', json_encode($this->capability()->tools()[0]->inputSchema, \JSON_THROW_ON_ERROR));
    }

    public function testWithoutARegistryEverySkillIsOffered(): void
    {
        $capability = new SkillCapability(self::skills());

        self::assertFalse($capability->execute(SkillCapability::TOOL, ['skill_name' => 'refunds'], self::context())->isError);
    }

    private function capability(): SkillCapability
    {
        $capability = new SkillCapability(self::skills(), fn (): CapabilityRegistry => $this->registry ?? throw new \LogicException('No registry.'));
        $this->registry = new CapabilityRegistry([$capability, new DirectoryCapability()]);

        return $capability;
    }

    private static function skills(): SkillRegistry
    {
        return new SkillRegistry([
            new Skill('refunds', 'Refund flow.', 'Rules.', ['issue_refund']),
            new Skill('records', 'Find a record.', 'Rules.', ['find_records']),
        ]);
    }

    private static function context(): ToolContext
    {
        return new ToolContext(new SessionContext('s-1', 'p-1'), new TurnState(), new Fence('site_content', 'Quoted.'), new Limits());
    }
}
