<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture;

use Odiseo\AiConversationalAgentBundle\Agent\AgentLoop;
use Odiseo\AiConversationalAgentBundle\Budget\BudgetPolicy;
use Odiseo\AiConversationalAgentBundle\Budget\ClientKeyResolver;
use Odiseo\AiConversationalAgentBundle\Budget\CostTable;
use Odiseo\AiConversationalAgentBundle\Budget\InMemorySpendLedger;
use Odiseo\AiConversationalAgentBundle\Budget\SpendLedger;
use Odiseo\AiConversationalAgentBundle\Capability\Capability;
use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Execution\ExecutorWording;
use Odiseo\AiConversationalAgentBundle\Execution\ToolExecutor;
use Odiseo\AiConversationalAgentBundle\Execution\ToolSurface;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Memory\InMemoryMemoryStore;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryCapability;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryRuntime;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryStore;
use Odiseo\AiConversationalAgentBundle\Presentation\SuggestionsCapability;
use Odiseo\AiConversationalAgentBundle\Prompt\ContextBlockBuilder;
use Odiseo\AiConversationalAgentBundle\Prompt\StaticPromptBuilder;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProvider;
use Odiseo\AiConversationalAgentBundle\Skill\SkillCapability;
use Odiseo\AiConversationalAgentBundle\Skill\SkillRegistry;

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
    /** The client the budget charges; null is a console run. */
    public ?string $clientKey = null;
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
            new BudgetPolicy($this->config, $this->ledger, new CostTable(), new class($this) implements ClientKeyResolver {
                public function __construct(private readonly AgentBuilder $builder)
                {
                }

                public function clientKey(): ?string
                {
                    return $this->builder->clientKey;
                }
            }),
        );
    }
}
