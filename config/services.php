<?php

declare(strict_types=1);

use Odiseo\AiConversationalAgentBundle\Agent\AgentLoop;
use Odiseo\AiConversationalAgentBundle\Agent\ContextProvider;
use Odiseo\AiConversationalAgentBundle\Agent\NullContextProvider;
use Odiseo\AiConversationalAgentBundle\Agent\TurnRunner;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Budget\RequestClientKeyResolver;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Command\ChatCommand;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Controller\ChatController;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Controller\MemoryController;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Controller\SessionController;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\EventListener\SessionWriteBackListener;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Session\SessionResolver;
use Odiseo\AiConversationalAgentBundle\Budget\BudgetPolicy;
use Odiseo\AiConversationalAgentBundle\Budget\ClientKeyResolver;
use Odiseo\AiConversationalAgentBundle\Budget\CostTable;
use Odiseo\AiConversationalAgentBundle\Budget\SpendLedger;
use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Capability\Limits;
use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Eval\EvalRunner;
use Odiseo\AiConversationalAgentBundle\Eval\Grader\CodeGrader;
use Odiseo\AiConversationalAgentBundle\Eval\Grader\JudgeGrader;
use Odiseo\AiConversationalAgentBundle\Execution\ExecutorWording;
use Odiseo\AiConversationalAgentBundle\Execution\HostToolInvoker;
use Odiseo\AiConversationalAgentBundle\Execution\ToolExecutor;
use Odiseo\AiConversationalAgentBundle\Execution\ToolSurface;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Host\ConsoleEnvironment;
use Odiseo\AiConversationalAgentBundle\Host\NullConsoleEnvironment;
use Odiseo\AiConversationalAgentBundle\Host\NullTurnHook;
use Odiseo\AiConversationalAgentBundle\Host\PrincipalResolver;
use Odiseo\AiConversationalAgentBundle\Host\TurnHook;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryCapability;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryRuntime;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryStore;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryWriteFilter;
use Odiseo\AiConversationalAgentBundle\Presentation\SuggestionsCapability;
use Odiseo\AiConversationalAgentBundle\Prompt\ContextBlockBuilder;
use Odiseo\AiConversationalAgentBundle\Prompt\StaticPromptBuilder;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProvider;
use Odiseo\AiConversationalAgentBundle\Session\SessionStore;
use Odiseo\AiConversationalAgentBundle\Skill\SkillCapability;
use Odiseo\AiConversationalAgentBundle\Skill\SkillRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * Every service is registered under an `odiseo_ai_conversational_agent.*` id with explicit
 * arguments; the classes and interfaces a host autowires are aliases. A host swaps a port by
 * redefining its alias (ModelProvider, ContextProvider, TurnHook…). The parameters come from the
 * bundle's configuration.
 */
return static function (ContainerConfigurator $container): void {
    $id = 'odiseo_ai_conversational_agent.';
    $capabilityTag = OdiseoAiConversationalAgentBundle::CAPABILITY_TAG;
    $services = $container->services();

    // Agent

    $services->set($id.'config', AgentConfig::class)->args([
        '$brandName' => param($id.'identity.brand_name'),
        '$assistantName' => param($id.'identity.assistant_name'),
        '$brandVoice' => param($id.'identity.brand_voice'),
        '$audience' => param($id.'identity.audience'),
        '$scope' => param($id.'identity.scope'),
        '$replyLanguage' => param($id.'identity.reply_language'),
        '$model' => param($id.'models.turn'),
        '$memoryModel' => param($id.'models.memory'),
        // Left as a string: resolved by AgentConfig so an env placeholder works here.
        '$thinkingEffort' => param($id.'models.thinking_effort'),
        '$maxTokens' => param($id.'budgets.max_tokens'),
        '$maxToolIterations' => param($id.'budgets.max_tool_iterations'),
        '$requestTimeoutSeconds' => param($id.'budgets.request_timeout'),
        '$sessionBudgetUsd' => param($id.'budgets.session_usd'),
        '$clientBudgetUsd' => param($id.'budgets.client_usd'),
        '$dailyBudgetUsd' => param($id.'budgets.daily_usd'),
        '$eagerToolDispatch' => param($id.'latency.eager_tool_dispatch'),
        '$rollingConversationCache' => param($id.'latency.rolling_conversation_cache'),
        '$closeOnPresentation' => param($id.'latency.close_on_presentation'),
        '$enableMemory' => param($id.'memory.enabled'),
        '$memoryTierOneCap' => param($id.'memory.tier_one_cap'),
        '$memoryBlockedPatterns' => param($id.'memory.blocked_patterns'),
        '$memoryRetentionDays' => param($id.'memory.retention_days'),
        '$limits' => service($id.'limits'),
        '$maxContextChars' => param($id.'limits.max_context_chars'),
        '$compactHistoryAboveTokens' => param($id.'limits.compact_history_above_tokens'),
    ]);
    $services->alias(AgentConfig::class, $id.'config');

    $services->set($id.'limits', Limits::class)->args([
        param($id.'limits.max_fenced_chars'),
        param($id.'limits.max_results_per_call'),
        param($id.'limits.max_components_per_turn'),
        param($id.'limits.max_chips_per_turn'),
    ]);
    $services->alias(Limits::class, $id.'limits');

    $services->set($id.'fence', Fence::class)->args([param($id.'fence.label'), param($id.'fence.notice')]);
    $services->alias(Fence::class, $id.'fence');

    // Empty unless `skills_dir` is set: the extension then builds it from that directory.
    $services->set($id.'skill.registry', SkillRegistry::class)->args([[]]);
    $services->alias(SkillRegistry::class, $id.'skill.registry');

    $services->set($id.'capability.registry', CapabilityRegistry::class)
        ->args([tagged_iterator($capabilityTag)]);
    $services->alias(CapabilityRegistry::class, $id.'capability.registry');
    $services->set($id.'capability.skill', SkillCapability::class)
        ->args([service($id.'skill.registry')])
        ->tag($capabilityTag);
    $services->set($id.'capability.memory', MemoryCapability::class)
        ->args([service($id.'memory.runtime')])
        ->tag($capabilityTag);
    $services->set($id.'capability.suggestions', SuggestionsCapability::class)
        ->args([service($id.'limits')])
        ->tag($capabilityTag);

    $services->set($id.'execution.wording', ExecutorWording::class);
    $services->alias(ExecutorWording::class, $id.'execution.wording');
    $services->set($id.'execution.tool_surface', ToolSurface::class)
        ->args([service($id.'capability.registry'), service($id.'execution.wording')]);
    $services->set($id.'execution.tool_executor', ToolExecutor::class)
        ->args([service($id.'capability.registry'), service($id.'execution.wording'), service('logger')]);
    $services->set($id.'execution.host_tool_invoker', HostToolInvoker::class)
        ->args([service($id.'execution.tool_executor'), service($id.'fence'), service($id.'limits')]);
    $services->alias(HostToolInvoker::class, $id.'execution.host_tool_invoker');

    $services->set($id.'prompt.static_builder', StaticPromptBuilder::class)->args([
        service($id.'config'),
        service($id.'capability.registry'),
        service($id.'skill.registry'),
        service($id.'fence'),
    ]);
    $services->set($id.'prompt.context_block_builder', ContextBlockBuilder::class)
        ->args([service($id.'fence')]);

    $services->set($id.'context_provider.null', NullContextProvider::class);
    $services->alias(ContextProvider::class, $id.'context_provider.null');

    $services->set($id.'agent.loop', AgentLoop::class)->args([
        service($id.'config'),
        service(ModelProvider::class),
        service($id.'capability.registry'),
        service($id.'execution.tool_executor'),
        service($id.'execution.tool_surface'),
        service($id.'prompt.static_builder'),
        service($id.'prompt.context_block_builder'),
        service($id.'fence'),
        service($id.'memory.runtime'),
        service($id.'budget.policy'),
        service(ContextProvider::class),
        service('logger'),
    ]);
    $services->alias(AgentLoop::class, $id.'agent.loop');

    $services->set($id.'turn_hook.null', NullTurnHook::class);
    $services->alias(TurnHook::class, $id.'turn_hook.null');

    $services->set($id.'agent.turn_runner', TurnRunner::class)->args([
        service($id.'agent.loop'),
        service(TurnHook::class),
        param($id.'identity.app_events_label'),
        service('logger'),
    ]);
    $services->alias(TurnRunner::class, $id.'agent.turn_runner');

    // Memory

    $services->set($id.'memory.write_filter', MemoryWriteFilter::class)
        ->args([param($id.'memory.blocked_patterns')]);
    $services->set($id.'memory.runtime', MemoryRuntime::class)->args([
        service(MemoryStore::class),
        service($id.'config'),
        service($id.'fence'),
        param($id.'memory.extraction_prompt'),
        service($id.'memory.write_filter'),
        service('logger'),
    ]);
    $services->alias(MemoryRuntime::class, $id.'memory.runtime');

    // Budget

    $services->set($id.'budget.cost_table', CostTable::class);
    $services->set($id.'budget.client_key_resolver', RequestClientKeyResolver::class)
        ->args([service('request_stack')]);
    $services->alias(ClientKeyResolver::class, $id.'budget.client_key_resolver');
    $services->set($id.'budget.policy', BudgetPolicy::class)->args([
        service($id.'config'),
        service(SpendLedger::class),
        service($id.'budget.cost_table'),
        service(ClientKeyResolver::class),
        service('logger'),
    ]);

    // Surfaces: the host names its principal and hooks; what it does not set falls back to a no-op.

    $services->set($id.'session.resolver', SessionResolver::class)->args([
        service(SessionStore::class),
        service(PrincipalResolver::class),
        param($id.'timezone'),
    ]);
    $services->alias(SessionResolver::class, $id.'session.resolver');

    $services->set($id.'event_listener.session_write_back', SessionWriteBackListener::class)
        ->args([service($id.'session.resolver'), service('logger')])
        ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onTerminate']);

    $services->set($id.'console_environment.null', NullConsoleEnvironment::class);
    $services->alias(ConsoleEnvironment::class, $id.'console_environment.null');

    $services->set($id.'controller.session', SessionController::class)
        ->public()
        ->args([service($id.'session.resolver'), service(PrincipalResolver::class), service('limiter.odiseo_agent_session_start')])
        ->tag('controller.service_arguments');
    $services->set($id.'controller.chat', ChatController::class)
        ->public()
        ->args([
            service($id.'session.resolver'),
            service($id.'agent.turn_runner'),
            service('logger'),
            service('limiter.odiseo_agent_chat_turn'),
            service('limiter.odiseo_agent_chat_turn_per_session'),
        ])
        ->tag('controller.service_arguments');
    $services->set($id.'controller.memory', MemoryController::class)
        ->public()
        ->args([service($id.'session.resolver'), service(MemoryStore::class)])
        ->tag('controller.service_arguments');

    $services->set($id.'command.chat', ChatCommand::class)
        ->args([
            service($id.'agent.turn_runner'),
            service(SessionStore::class),
            service($id.'session.resolver'),
            service(ConsoleEnvironment::class),
        ])
        ->tag('console.command');

    // Evals

    $services->set($id.'eval.code_grader', CodeGrader::class);
    $services->set($id.'eval.judge_grader', JudgeGrader::class)
        ->args([service(ModelProvider::class), service($id.'fence'), param($id.'models.judge')]);
    $services->set($id.'eval.runner', EvalRunner::class)->args([
        service($id.'agent.loop'),
        service(MemoryStore::class),
        service($id.'eval.code_grader'),
        service($id.'eval.judge_grader'),
        param($id.'eval.timezone'),
    ]);
    $services->alias(EvalRunner::class, $id.'eval.runner');
};
