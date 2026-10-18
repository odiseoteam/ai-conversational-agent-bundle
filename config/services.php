<?php

declare(strict_types=1);

use Odiseo\AiConversationalAgentBundle\Agent\AgentLoop;
use Odiseo\AiConversationalAgentBundle\Agent\ContextProviderInterface;
use Odiseo\AiConversationalAgentBundle\Agent\NullContextProvider;
use Odiseo\AiConversationalAgentBundle\Agent\TurnRunner;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Budget\RequestClientKeyResolver;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Command\ChatCommand;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Command\EvalCommand;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Controller\ChatController;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Controller\MemoryController;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Controller\SessionController;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\EventListener\MemoryExtractionListener;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\EventListener\SessionWriteBackListener;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Messenger\ExtractMemoryHandler;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\ProviderRegistry;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\RoutedProvider;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Session\SessionResolver;
use Odiseo\AiConversationalAgentBundle\Budget\BudgetPolicy;
use Odiseo\AiConversationalAgentBundle\Budget\ClientKeyResolverInterface;
use Odiseo\AiConversationalAgentBundle\Budget\CostTable;
use Odiseo\AiConversationalAgentBundle\Budget\NullSpendScopeResolver;
use Odiseo\AiConversationalAgentBundle\Budget\SpendLedgerInterface;
use Odiseo\AiConversationalAgentBundle\Budget\SpendScopeResolverInterface;
use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Capability\Limits;
use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Config\AgentConfigProviderInterface;
use Odiseo\AiConversationalAgentBundle\Config\ConfigOverridesInterface;
use Odiseo\AiConversationalAgentBundle\Config\NullConfigOverrides;
use Odiseo\AiConversationalAgentBundle\Config\RuntimeAgentConfig;
use Odiseo\AiConversationalAgentBundle\Content\InMemoryVersionStore;
use Odiseo\AiConversationalAgentBundle\Content\RevisionInterface;
use Odiseo\AiConversationalAgentBundle\Content\VersionStoreInterface;
use Odiseo\AiConversationalAgentBundle\Eval\EvalEnvironmentInterface;
use Odiseo\AiConversationalAgentBundle\Eval\EvalIsolationInterface;
use Odiseo\AiConversationalAgentBundle\Eval\EvalRunner;
use Odiseo\AiConversationalAgentBundle\Eval\Grader\CodeGrader;
use Odiseo\AiConversationalAgentBundle\Eval\Grader\JudgeGrader;
use Odiseo\AiConversationalAgentBundle\Eval\NullEvalEnvironment;
use Odiseo\AiConversationalAgentBundle\Eval\NullEvalIsolation;
use Odiseo\AiConversationalAgentBundle\Execution\ExecutorWording;
use Odiseo\AiConversationalAgentBundle\Execution\HostToolInvoker;
use Odiseo\AiConversationalAgentBundle\Execution\ToolExecutor;
use Odiseo\AiConversationalAgentBundle\Execution\ToolSurface;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Host\ConsoleEnvironmentInterface;
use Odiseo\AiConversationalAgentBundle\Host\NullConsoleEnvironment;
use Odiseo\AiConversationalAgentBundle\Host\NullTurnHook;
use Odiseo\AiConversationalAgentBundle\Host\PrincipalResolverInterface;
use Odiseo\AiConversationalAgentBundle\Host\TurnHookInterface;
use Odiseo\AiConversationalAgentBundle\Knowledge\GuideIndex;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeBase;
use Odiseo\AiConversationalAgentBundle\Knowledge\LexicalSearch;
use Odiseo\AiConversationalAgentBundle\Knowledge\MarkdownKnowledgeSource;
use Odiseo\AiConversationalAgentBundle\Knowledge\VersionedKnowledgeSource;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryCapability;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryRetentionInterface;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryRuntime;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryStoreInterface;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryWriteFilter;
use Odiseo\AiConversationalAgentBundle\Memory\NullMemoryRetention;
use Odiseo\AiConversationalAgentBundle\Presentation\SuggestionsCapability;
use Odiseo\AiConversationalAgentBundle\Prompt\ContextBlockBuilder;
use Odiseo\AiConversationalAgentBundle\Prompt\StaticPromptBuilder;
use Odiseo\AiConversationalAgentBundle\Provider\ApiKeyResolverInterface;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProviderInterface;
use Odiseo\AiConversationalAgentBundle\Provider\NullApiKeyResolver;
use Odiseo\AiConversationalAgentBundle\Rule\ConfiguredRuleSource;
use Odiseo\AiConversationalAgentBundle\Rule\RuleBook;
use Odiseo\AiConversationalAgentBundle\Rule\VersionedRuleSource;
use Odiseo\AiConversationalAgentBundle\Session\CurrentSession;
use Odiseo\AiConversationalAgentBundle\Session\SessionStore;
use Odiseo\AiConversationalAgentBundle\Skill\SkillCapability;
use Odiseo\AiConversationalAgentBundle\Skill\SkillRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

/*
 * Every service is registered under an `odiseo_ai_conversational_agent.*` id with explicit
 * arguments; the classes and interfaces a host autowires are aliases. A host swaps a port by
 * redefining its alias (ContextProviderInterface, TurnHookInterface, ConfigOverridesInterface…), and a role's
 * model provider by redefining `odiseo_ai_conversational_agent.provider.<role>`. The parameters come from the
 * bundle's configuration; the runtime settings lay the host's ConfigOverridesInterface over them.
 */
return static function (ContainerConfigurator $container): void {
    $id = 'odiseo_ai_conversational_agent.';
    $capabilityTag = OdiseoAiConversationalAgentBundle::CAPABILITY_TAG;
    $logChannel = ['channel' => OdiseoAiConversationalAgentBundle::LOG_CHANNEL];
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
        '$platform' => param($id.'models.turn_platform'),
        '$memoryPlatform' => param($id.'models.memory_platform'),
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

    // The settings in force: the configured ones with the host's runtime changes on top. Every
    // service that runs a turn reads these.
    $services->set($id.'config_overrides.null', NullConfigOverrides::class);
    $services->alias(ConfigOverridesInterface::class, $id.'config_overrides.null');
    $services->set($id.'config.runtime', RuntimeAgentConfig::class)
        ->args([service($id.'config'), service(ConfigOverridesInterface::class), service('logger')])
        ->tag('monolog.logger', $logChannel);
    $services->alias(AgentConfigProviderInterface::class, $id.'config.runtime');

    $services->set($id.'session.current', CurrentSession::class);
    $services->alias(CurrentSession::class, $id.'session.current');

    $services->set($id.'memory.retention.null', NullMemoryRetention::class);
    $services->alias(MemoryRetentionInterface::class, $id.'memory.retention.null');

    $services->set($id.'api_key_resolver.null', NullApiKeyResolver::class);
    $services->alias(ApiKeyResolverInterface::class, $id.'api_key_resolver.null');

    // What people edit at runtime, as versions; in memory unless the ORM has a content_version
    // class, in which case the extension points the alias at the ORM store.
    $services->set($id.'store.version.in_memory', InMemoryVersionStore::class);
    $services->alias(VersionStoreInterface::class, $id.'store.version.in_memory');
    $services->alias(RevisionInterface::class, VersionStoreInterface::class);

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
        ->args([service($id.'capability.registry'), service($id.'execution.wording'), service($id.'limits')]);
    $services->set($id.'execution.tool_executor', ToolExecutor::class)
        ->args([service($id.'capability.registry'), service($id.'execution.wording'), service('logger')])
        ->tag('monolog.logger', $logChannel);
    $services->set($id.'execution.host_tool_invoker', HostToolInvoker::class)
        ->args([service($id.'execution.tool_executor'), service($id.'fence'), service($id.'limits')]);
    $services->alias(HostToolInvoker::class, $id.'execution.host_tool_invoker');

    // Rules: the configured ones with what was published over them, plus any RuleSourceInterface
    // the host tags. The version store is in memory unless the ORM has a content_version class.
    $services->set($id.'rule.configured_source', ConfiguredRuleSource::class)
        ->args([param($id.'rules')]);
    $services->set($id.'rule.versioned_source', VersionedRuleSource::class)
        ->args([service($id.'rule.configured_source'), service(VersionStoreInterface::class)])
        ->tag(OdiseoAiConversationalAgentBundle::RULE_SOURCE_TAG);
    $services->set($id.'rule.book', RuleBook::class)->args([
        tagged_iterator(OdiseoAiConversationalAgentBundle::RULE_SOURCE_TAG),
        param($id.'limits.max_rules'),
        param($id.'limits.max_rule_chars'),
        service('logger'),
        service(RevisionInterface::class),
    ])->tag('monolog.logger', $logChannel);
    $services->alias(RuleBook::class, $id.'rule.book');

    // Knowledge: the lexical search over every tagged source (the Markdown files when knowledge_dir
    // is set, with what was published over them), merged with any other KnowledgeSearchInterface the host tags.
    $services->set($id.'knowledge.markdown_source', MarkdownKnowledgeSource::class)
        ->args([param($id.'knowledge_dir')]);
    $services->set($id.'knowledge.versioned_source', VersionedKnowledgeSource::class)
        ->args([service(VersionStoreInterface::class), service($id.'knowledge.markdown_source')->nullOnInvalid()])
        ->tag(OdiseoAiConversationalAgentBundle::KNOWLEDGE_SOURCE_TAG);
    $services->set($id.'knowledge.lexical_search', LexicalSearch::class)
        ->args([tagged_iterator(OdiseoAiConversationalAgentBundle::KNOWLEDGE_SOURCE_TAG), 3000, 0.5, service('logger')])
        ->tag(OdiseoAiConversationalAgentBundle::KNOWLEDGE_SEARCH_TAG)
        ->tag('monolog.logger', $logChannel);
    $services->set($id.'knowledge.base', KnowledgeBase::class)
        ->args([tagged_iterator(OdiseoAiConversationalAgentBundle::KNOWLEDGE_SEARCH_TAG), service('logger')])
        ->tag('monolog.logger', $logChannel);
    $services->alias(KnowledgeBase::class, $id.'knowledge.base');
    $services->set($id.'knowledge.guide_index', GuideIndex::class)->args([
        tagged_iterator(OdiseoAiConversationalAgentBundle::KNOWLEDGE_SOURCE_TAG),
        param($id.'limits.max_guides'),
        param($id.'limits.max_guide_when_chars'),
        service('logger'),
        service(RevisionInterface::class),
    ])->tag('monolog.logger', $logChannel);

    $services->set($id.'prompt.static_builder', StaticPromptBuilder::class)->args([
        service($id.'config.runtime'),
        service($id.'capability.registry'),
        service($id.'skill.registry'),
        service($id.'fence'),
        service($id.'rule.book'),
        service($id.'knowledge.guide_index'),
        param($id.'guides_notice'),
    ]);
    $services->set($id.'prompt.context_block_builder', ContextBlockBuilder::class)
        ->args([service($id.'fence')]);

    $services->set($id.'context_provider.null', NullContextProvider::class);
    $services->alias(ContextProviderInterface::class, $id.'context_provider.null');

    // One provider per role: the turn's and the memory's follow the platform in force on each call,
    // the judge's is picked when its service is built. A host replaces a role's provider by
    // redefining its service.
    $services->set($id.'provider.registry', ProviderRegistry::class)
        ->args([tagged_locator(OdiseoAiConversationalAgentBundle::PROVIDER_TAG, 'platform'), param($id.'capabilities')]);
    foreach ([RoutedProvider::TURN, RoutedProvider::MEMORY] as $role) {
        $services->set($id.'provider.'.$role, RoutedProvider::class)
            ->args([service($id.'provider.registry'), service($id.'config.runtime'), $role]);
    }
    $services->set($id.'provider.judge', ModelProviderInterface::class)
        ->factory([service($id.'provider.registry'), 'get'])
        ->args([param($id.'models.judge_platform')]);

    $services->set($id.'agent.loop', AgentLoop::class)->args([
        service($id.'config.runtime'),
        service($id.'provider.turn'),
        service($id.'capability.registry'),
        service($id.'execution.tool_executor'),
        service($id.'execution.tool_surface'),
        service($id.'prompt.static_builder'),
        service($id.'prompt.context_block_builder'),
        service($id.'fence'),
        service($id.'memory.runtime'),
        service($id.'budget.policy'),
        service(ContextProviderInterface::class),
        service('logger'),
        service($id.'provider.memory'),
        service($id.'session.current'),
    ])->tag('monolog.logger', $logChannel);
    $services->alias(AgentLoop::class, $id.'agent.loop');

    $services->set($id.'turn_hook.null', NullTurnHook::class);
    $services->alias(TurnHookInterface::class, $id.'turn_hook.null');

    $services->set($id.'agent.turn_runner', TurnRunner::class)->args([
        service($id.'agent.loop'),
        service(TurnHookInterface::class),
        param($id.'identity.app_events_label'),
        service('logger'),
    ])->tag('monolog.logger', $logChannel);
    $services->alias(TurnRunner::class, $id.'agent.turn_runner');

    // Memory

    $services->set($id.'memory.write_filter', MemoryWriteFilter::class)
        ->args([param($id.'memory.blocked_patterns')]);
    $services->set($id.'memory.runtime', MemoryRuntime::class)->args([
        service(MemoryStoreInterface::class),
        service($id.'config.runtime'),
        service($id.'fence'),
        param($id.'memory.extraction_prompt'),
        service($id.'memory.write_filter'),
    ]);
    $services->alias(MemoryRuntime::class, $id.'memory.runtime');

    // Budget

    $services->set($id.'budget.cost_table', CostTable::class)
        ->args([param($id.'prices'), [param($id.'models.turn'), param($id.'models.memory'), param($id.'models.judge')]]);
    $services->set($id.'budget.client_key_resolver', RequestClientKeyResolver::class)
        ->args([service('request_stack')]);
    $services->alias(ClientKeyResolverInterface::class, $id.'budget.client_key_resolver');
    $services->set($id.'budget.spend_scope_resolver.null', NullSpendScopeResolver::class);
    $services->alias(SpendScopeResolverInterface::class, $id.'budget.spend_scope_resolver.null');
    $services->set($id.'budget.policy', BudgetPolicy::class)->args([
        service($id.'config.runtime'),
        service(SpendLedgerInterface::class),
        service($id.'budget.cost_table'),
        service(ClientKeyResolverInterface::class),
        service('logger'),
        service(SpendScopeResolverInterface::class),
    ])->tag('monolog.logger', $logChannel);

    // Surfaces: the host names its principal and hooks; what it does not set falls back to a no-op.

    $services->set($id.'session.resolver', SessionResolver::class)->args([
        service(SessionStore::class),
        service(PrincipalResolverInterface::class),
        param($id.'timezone'),
    ]);
    $services->alias(SessionResolver::class, $id.'session.resolver');

    $services->set($id.'event_listener.session_write_back', SessionWriteBackListener::class)
        ->args([service($id.'session.resolver'), service('logger')])
        ->tag('monolog.logger', $logChannel)
        ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onTerminate']);
    $services->set($id.'event_listener.memory_extraction', MemoryExtractionListener::class)
        ->args([service($id.'agent.turn_runner'), service('logger'), service('messenger.default_bus')->nullOnInvalid()])
        ->tag('monolog.logger', $logChannel)
        ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onTerminate']);
    $services->set($id.'messenger.extract_memory_handler', ExtractMemoryHandler::class)
        ->args([service($id.'agent.loop')])
        ->tag('messenger.message_handler');

    $services->set($id.'console_environment.null', NullConsoleEnvironment::class);
    $services->alias(ConsoleEnvironmentInterface::class, $id.'console_environment.null');

    $services->set($id.'controller.session', SessionController::class)
        ->public()
        ->args([service($id.'session.resolver'), service(PrincipalResolverInterface::class), service('limiter.odiseo_agent_session_start')])
        ->tag('controller.service_arguments');
    $services->set($id.'controller.chat', ChatController::class)
        ->public()
        ->args([
            service($id.'session.resolver'),
            service($id.'agent.turn_runner'),
            service('logger'),
            service('limiter.odiseo_agent_chat_turn'),
            service('limiter.odiseo_agent_chat_turn_per_session'),
            service($id.'event_listener.memory_extraction'),
        ])
        ->tag('monolog.logger', $logChannel)
        ->tag('controller.service_arguments');
    $services->set($id.'controller.memory', MemoryController::class)
        ->public()
        ->args([service($id.'session.resolver'), service(MemoryStoreInterface::class)])
        ->tag('controller.service_arguments');

    $services->set($id.'command.chat', ChatCommand::class)
        ->args([
            service($id.'agent.turn_runner'),
            service(SessionStore::class),
            service($id.'session.resolver'),
            service(ConsoleEnvironmentInterface::class),
        ])
        ->tag('console.command');

    // Evals: the host points EvalEnvironmentInterface at its own preconditions; with the ORM the cases run
    // in a transaction that is rolled back.

    $services->set($id.'eval.environment.null', NullEvalEnvironment::class);
    $services->alias(EvalEnvironmentInterface::class, $id.'eval.environment.null');
    $services->set($id.'eval.isolation.null', NullEvalIsolation::class);
    $services->alias(EvalIsolationInterface::class, $id.'eval.isolation.null');

    $services->set($id.'eval.code_grader', CodeGrader::class)->tag(OdiseoAiConversationalAgentBundle::EVAL_GRADER_TAG);
    $services->set($id.'eval.judge_grader', JudgeGrader::class)
        ->args([service($id.'provider.judge'), service($id.'fence'), param($id.'models.judge'), service($id.'budget.cost_table')]);
    $services->set($id.'eval.runner', EvalRunner::class)->args([
        service($id.'agent.turn_runner'),
        service(SessionStore::class),
        service(MemoryStoreInterface::class),
        service(SpendLedgerInterface::class),
        service(PrincipalResolverInterface::class),
        tagged_iterator(OdiseoAiConversationalAgentBundle::EVAL_GRADER_TAG),
        service($id.'eval.judge_grader'),
        service(EvalEnvironmentInterface::class),
        service(EvalIsolationInterface::class),
        param($id.'eval.timezone'),
    ]);
    $services->alias(EvalRunner::class, $id.'eval.runner');

    $services->set($id.'command.eval', EvalCommand::class)
        ->args([
            service($id.'eval.runner'),
            service(ConsoleEnvironmentInterface::class),
            service($id.'config'),
            param($id.'evals_dir'),
            param('kernel.project_dir').'/var/evals',
            param($id.'models.judge'),
        ])
        ->tag('console.command');
};
