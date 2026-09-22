<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Skill;

use Odiseo\AiConversationalAgentBundle\Capability\Capability;
use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Capability\ToolContext;
use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;

/**
 * The flows' rules, loaded on demand. The static prompt carries only the index, so a rule that
 * applies to one flow costs nothing on the turns that are not that flow.
 */
final class SkillCapability implements Capability
{
    public const TOOL = 'load_skill';

    /**
     * @param (\Closure(): CapabilityRegistry)|null $registry deferred, since the registry holds this capability too;
     *                                                        null offers every skill
     */
    public function __construct(
        private readonly SkillRegistry $skills,
        private readonly ?\Closure $registry = null,
    ) {
    }

    /** The skills whose required tools the other capabilities provide. */
    public function available(): SkillRegistry
    {
        if (null === $this->registry) {
            return $this->skills;
        }

        $tools = [];
        foreach (($this->registry)()->all() as $capability) {
            if ($capability === $this) {
                continue;
            }
            foreach ($capability->tools() as $tool) {
                $tools[] = $tool->name;
            }
        }

        return $this->skills->availableWith($tools);
    }

    public function name(): string
    {
        return 'core.skills';
    }

    public function tools(): array
    {
        $names = $this->available()->names();
        if ([] === $names) {
            return [];
        }

        return [new ToolSpec(
            self::TOOL,
            'Load the rules of the flow whose entry in the skill index the request matches; they are not in your prompt. Call it in the same round as the flow\'s first read and follow them for the rest of the flow.',
            [
                'type' => 'object',
                'properties' => [
                    'skill_name' => [
                        'type' => 'string',
                        'enum' => $names,
                        'description' => 'Name of the skill as listed in the index.',
                    ],
                ],
                'required' => ['skill_name'],
                'additionalProperties' => false,
            ],
        )];
    }

    public function promptFragments(): array
    {
        return [];
    }

    public function groundingRules(): array
    {
        return [];
    }

    public function components(): array
    {
        return [];
    }

    public function execute(string $tool, array $input, ToolContext $context): ToolOutcome
    {
        $name = (string) ($input['skill_name'] ?? '');
        $skills = $this->available();
        $body = $skills->instructions($name);

        if (null === $body) {
            return ToolOutcome::error(\sprintf(
                'No skill named "%s". Available: %s',
                $name,
                implode(', ', $skills->names()),
            ));
        }

        return new ToolOutcome($body);
    }
}
