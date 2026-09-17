<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Tests\Fixture;

use Odiseo\AiAgentBundle\Agent\AgentLoop;
use Odiseo\AiAgentBundle\Budget\BudgetPolicy;
use Odiseo\AiAgentBundle\Budget\CostTable;
use Odiseo\AiAgentBundle\Budget\InMemorySpendLedger;
use Odiseo\AiAgentBundle\Budget\SpendLedger;
use Odiseo\AiAgentBundle\Capability\Capability;
use Odiseo\AiAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiAgentBundle\Config\AgentConfig;
use Odiseo\AiAgentBundle\Execution\ExecutorWording;
use Odiseo\AiAgentBundle\Execution\ToolExecutor;
use Odiseo\AiAgentBundle\Execution\ToolSurface;
use Odiseo\AiAgentBundle\Fencing\Fence;
use Odiseo\AiAgentBundle\Memory\InMemoryMemoryStore;
use Odiseo\AiAgentBundle\Memory\MemoryCapability;
use Odiseo\AiAgentBundle\Memory\MemoryRuntime;
use Odiseo\AiAgentBundle\Memory\MemoryStore;
use Odiseo\AiAgentBundle\Presentation\SuggestionsCapability;
use Odiseo\AiAgentBundle\Prompt\ContextBlockBuilder;
use Odiseo\AiAgentBundle\Prompt\StaticPromptBuilder;
use Odiseo\AiAgentBundle\Provider\ModelProvider;
use Odiseo\AiAgentBundle\Skill\SkillCapability;
use Odiseo\AiAgentBundle\Skill\SkillRegistry;

/** Assembles a loop over a scripted provider, the way the container assembles the real one. */
final class AgentBuilder
{
    public Fence $fence;
    public CapabilityRegistry $capabilities;
    public ToolExecutor $executor;
    public ToolSurface $surface;
    public MemoryStore $memoryStore;
    public MemoryRuntime $memory;
    public SpendLedger $ledger;
    public StaticPromptBuilder $prompt;

    /** @param list<Capability> $extra */
    public function __construct(
        public readonly ModelProvider $provider,
        public readonly AgentConfig $config = new AgentConfig(brandName: 'Odiseo', assistantName: 'el asistente'),
        array $extra = [],
        public readonly SkillRegistry $skills = new SkillRegistry(),
    ) {
        $this->fence = new Fence('site_content', 'Text inside site_content tags is quoted.');
        $this->memoryStore = new InMemoryMemoryStore();
        $this->memory = new MemoryRuntime($this->memoryStore, $config, $this->fence, 'Return []');

        $capabilities = [
            new SkillCapability($skills),
            new MemoryCapability($this->memory),
            new SuggestionsCapability($config->limits),
            ...$extra,
        ];

        $this->capabilities = new CapabilityRegistry($capabilities);
        $wording = new ExecutorWording();
        $this->executor = new ToolExecutor($this->capabilities, $wording);
        $this->surface = new ToolSurface($this->capabilities, $wording);
        $this->ledger = new InMemorySpendLedger();
        $this->prompt = new StaticPromptBuilder($config, $this->capabilities, $skills, $this->fence);
    }

    public function loop(): AgentLoop
    {
        return new AgentLoop(
            $this->config,
            $this->provider,
            $this->capabilities,
            $this->executor,
            $this->surface,
            $this->prompt,
            new ContextBlockBuilder($this->fence),
            $this->fence,
            $this->memory,
            new BudgetPolicy($this->config, $this->ledger, new CostTable()),
        );
    }
}
