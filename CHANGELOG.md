# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `retry: true` in the chat request: when the stored conversation ends on that same message with
  no reply (the turn that failed or hit a cap), the turn answers it again instead of storing it
  twice. `TurnRunner::run()` takes it as `$retry`.

## [0.3.0] - 2026-10-21

### Added

- `Capability\ScopedCapabilityInterface` and `ScopeArea`: a capability names an area of what the
  assistant does, and the prompt's "Stay within …" line lists the registered ones by priority
  ("a and b", "a, b, and c"). Without any, it says `StaticPromptBuilder::DEFAULT_SCOPE`.

- Runtime settings: `Config\ConfigOverridesInterface` (no changes by default) lays a host's saved
  settings over the configuration for the current request, through `RuntimeAgentConfig`, the
  `Config\AgentConfigProviderInterface` that `AgentLoop`, `BudgetPolicy`, `MemoryRuntime` and
  `StaticPromptBuilder` now read. `AgentConfig` is its own provider, so code that builds these
  services with a config keeps working.
- `AgentConfig::$platform` and `$memoryPlatform`: the turn's and the memory's providers are
  `Bridge\Symfony\RoutedProvider`, which picks the adapter from the platform in force on each
  call. The judge's is picked when its service is built, as before.
- `Provider\ApiKeyResolverInterface`: a runtime key per platform. Each adapter calls through a
  `Provider\Platform\KeyedPlatform`, which `KeyedPlatformPass` lets rebuild the configured
  platform (same factory, HTTP client, catalog and cache settings) with that key.
- `Budget\SpendScopeResolverInterface` and `AgentConfig::$scopeDailyBudgetUsd`: a call is
  recorded under a scope (`SpendEntry::$scope`, new column and index) and a scope can have its own
  day cap. `SpendLedgerInterface` gains `scopeSpend()` and a `$scope` argument on `record()`.
- Versions of what people edit: `Content\VersionStoreInterface`, in memory or over Doctrine
  (`orm.classes.content_version`, optional, extending `Bridge\Doctrine\Model\ContentVersion`),
  with drafts, publications and "back to the default". `Rule\VersionedRuleSource` and
  `Knowledge\VersionedKnowledgeSource` lay the published rules and documents over the configured
  ones; `RuleBook`, `GuideIndex` and the cached prompt are built again when the store's revision
  changes.
- `Session\CurrentSession`: the session a turn or a memory extraction runs for, for a host's
  settings where there is no request.
- `ProviderRegistry::platforms()`: the platforms with an adapter installed.
- `Memory\MemoryRetentionInterface`: the memory retention a host sets at runtime over
  `memory.retention_days`; `agent:prune` uses it.
- `text_before_tool_calls` capability (`ProviderCapabilities::$textBeforeToolCalls`, true by
  default, false for Claude Sonnet 5.5, Opus 5.5 and Fable 5): these models return a note longer
  than a sentence or two written before a tool call as a progress update inside a thinking
  block, never as text, so a reply written before the chips was never shown. For such a model
  the chips tool takes the reply first, in `reply` (`ChipComponent::REPLY_FIELD`), the prompt
  gets a "# Replies with chips" section, and the reply is shown as text while it is written
  (`PartialJson::stringSoFar()`); it counts as the turn's text for the `chips_alone` hold, whose
  note for these models is `ExecutorWording::$chipsAloneReplyText`, and it is kept in the
  message's text and in what the memory reads. Other models see no change.
- `ToolSpec::$streamsInput`: the Anthropic adapter asks for the tool's arguments as they are
  written (`eager_input_streaming`) instead of buffered. The chips tool sets it when it carries
  the reply.
- The guide index goes inside the fence, sanitized, since its titles and "when" lines are content:
  a guide written in an admin or by another system cannot reach the prompt as an instruction.
- `PromptSection::Guidance`: a section of the cached prompt, right before the house rules, where
  a capability says how the vertical wants requests handled (a vertical's way of guiding a
  choice, say). It is only there when a capability adds to it.
- The guide index: the `kind: guide` documents of every knowledge source are listed (title and
  `when`) in a "# Guides" section of the cached prompt, after the skills, so the model knows a
  guide exists before it searches. `GuideIndex` orders them by title and caps them
  (`limits.max_guides`, `limits.max_guide_when_chars`), leaving out and logging the rest;
  `guides_notice` is the line above the list.
- Knowledge: `KnowledgeBase::search()` over the organisation's policies, guides and snippets
  (`KnowledgeDocument`, `KnowledgeHit`). `knowledge_dir` holds Markdown files with front matter
  (`id`, `title`, `kind`, `when`, `url`, `enabled`). `LexicalSearch` ranks the sections of every
  `KnowledgeSourceInterface` together with BM25, without case or accents and by word stem, and
  returns a section whole; a hit under half the best one's score is left out. `KnowledgeSearchInterface` adds a search that ranks its own hits (a
  search engine, a vector store), merged by position. Sources and searches are autoconfigured; one
  that fails is left out and logged.
- `PresentationComponent::$chipNote`: what the card already shows and which chips fit beside it.
  It follows the description of a tool that takes the chips in a field, so they stop repeating the card
  (a checkout button already on it, a product already in the carousel).
- Rules: the organisation's behaviour rules, configured under `rules` (title, category, text,
  enabled) and rendered as a "House rules" section of the cached prompt, before the trust and
  boundary sections. `RuleSourceInterface` adds other sources, tagged
  `odiseo_ai_conversational_agent.rule_source`; `limits.max_rules` and `limits.max_rule_chars`
  cap them: the configuration fails when the container is built, another source's rule is left
  out and logged.
- Claude Haiku 5.5 (`claude-haiku-5-5`): its price, and no temperature on the request.
- `editable_history` capability (`ProviderCapabilities::$editableHistory`, true by default): a
  model without it checks that the history before a replayed thinking block was not edited, so
  the history is not compacted while it thinks. False on Anthropic for Fable 5.1, Opus 5.5,
  Sonnet 5.5 and Haiku 5.5.
- `ChipMode` on `PresentationComponent`: where the chips of a turn that ends on it come from.
  `Field` adds an optional `suggestions` field, last in the tool's schema; the executor sanitizes
  the chips and emits the `suggestions` component after the card, so the round that renders it
  ends the turn. Chips sent with a card that dropped something are not shown, and the result says
  so. `RequiredField` is the same field, required by the schema; a call without it still renders.
  `None` is a card that ends the turn without chips. `Tool`, the default, keeps the chips tool.
  The prompt's chips rule follows the modes the deployment registers.
- `closes_on` in the eval code grader: the tool the turn's last round must call.
- `ToolInputCheck`: before a tool runs, its arguments are checked against its schema. A required
  argument missing, a value outside its enum, or an argument the schema does not declare where
  `additionalProperties` is `false` stops the call, and the model gets an error naming each
  problem (and where a misplaced argument goes) so it can call again. The text is
  `ExecutorWording::$invalidInputText`. Types are still left to the handlers.
- The chips tool called in a turn that has no text and no component yet is held
  (`chips_alone`) and the model is told to write the reply first, so a model that calls tools
  without writing (OpenAI's) no longer ends a turn with chips and nothing answered. The text is
  `ExecutorWording::$chipsAloneText`.
- `text_beside_tool_calls` capability (`ProviderCapabilities::$textBesideToolCalls`, true by
  default, false for the OpenAI adapter): a model without it gets one more section at the end of
  the turn's static prompt saying a response can carry text and tool calls together, so the
  sentence a rule asks for is written with the calls instead of dropped. The shared prompt is
  unchanged and stays the cached prefix; the memory extraction and the judge do not get it.
  `StaticPromptBuilder::buildFor()` returns the prompt for one model's capabilities.

### Changed

- `identity.scope` and `AgentConfig::$scope` are removed: what the assistant stays within comes
  from the capabilities (`ScopedCapabilityInterface`). Remove the key from the configuration.
- `StaticPromptBuilder` keeps its text per identity, capability fragments, rules and guides,
  instead of once per process.
- No exclamation marks or emoji only on turns that add, stage or confirm; elsewhere the voice
  decides. It applied to every turn.
- The configured rules and the Markdown files are no longer tagged themselves: the versioned
  sources wrap them (`odiseo_ai_conversational_agent.rule.versioned_source`,
  `odiseo_ai_conversational_agent.knowledge.versioned_source`).
- Every port interface ends in `Interface`, like the model interfaces already did:
  `CapabilityInterface`, `ContextProviderInterface`, `ConversationInitializerInterface`,
  `ClientKeyResolverInterface`, `SpendLedgerInterface`, `EvalEnvironmentInterface`,
  `EvalIsolationInterface`, `GraderInterface`, `DomainErrorMapperInterface`,
  `ConsoleEnvironmentInterface`, `PrincipalResolverInterface`, `TurnHookInterface`,
  `MemoryStoreInterface`, `ModelProviderInterface`, `StreamEventInterface` and the new
  `RuleSourceInterface`. Implement and alias the new names.
- Default models: the turn and the memory extraction run on Claude Haiku 5.5
  (`claude-haiku-5-5`, the turn still at `low` effort) and the eval judge on Claude Sonnet 5.5
  (`claude-sonnet-5-5`), instead of Sonnet 5, Haiku 4.5 and Sonnet 5. Verdicts stored with the
  old judge no longer match its fingerprint and are scored again. Both models are declared in
  Symfony AI's `ai.model.anthropic` until its catalog lists them.
- The chips tool is listed after every other tool.
- The loop and its services log to the `conversational_agent` Monolog channel
  (`OdiseoAiConversationalAgentBundle::LOG_CHANNEL`) instead of `app`. An application that
  filtered the `model call` lines by channel follows the new one.
- The memory extraction no longer runs inside the streamed turn. The chat controller leaves an
  `ExtractMemory` message and a `kernel.terminate` listener dispatches it once the response is
  out: unrouted, Messenger handles it in the same process; routed to a transport, a worker does.
  `agent:chat` and the evals extract in place. `symfony/messenger` is now required.
- `TurnRunner::run()` only runs the turn; `memoryOf()` and `remember()` extract. A failed
  extraction is raised by `AgentLoop::extractMemory()` instead of being swallowed in
  `MemoryRuntime`, so a transport can retry it.

### Fixed

- `prices` and `capabilities` keep a model's name as written: Symfony turned the dashes of
  `claude-sonnet-5-5` into underscores, so a price or a capability configured for a model with
  dashes was never applied.

## [0.2.0] - 2026-10-03

### Added

- The OpenAI adapter (`openai`), over `symfony/ai-open-ai-platform` and the Responses API, loaded
  when the bridge is installed. The system blocks go as developer messages, tool calls and
  results as `function_call` and `function_call_output`, and a reasoning model's encrypted items
  are kept in the transcript and sent back (`store: false`). `prompt_cache_key` keeps a
  conversation's rounds on one cache; cached input is counted as a cache read. The effort maps
  to `none`…`xhigh`. A call cut at the output limit, which the bridge reports without usage, is
  charged the output cap. Prices for gpt-5.5, gpt-5.4, gpt-5.4-mini and gpt-5-mini.
- `TurnRequest::$cacheKey`, set by the loop to the session tag.
- Doctrine ORM stores for sessions, memory and spend (`OrmSessionStore`, `OrmMemoryStore`,
  `OrmSpendLedger`) over four mapped superclasses in `Bridge\Doctrine\Model`. The host names its
  entities in `orm.classes`; with `orm.enabled: false` the stores stay in memory.
- `agent:prune` command, with `conversations.retention_days`, `sessions.retention_days` and
  `memory.retention_days`.
- Per-client daily budget (`budgets.client_usd`), keyed by IP.
- `ConversationInitializer`, for the host to fill its own conversation fields when one starts.
- `Scalar::stringMap()`, to narrow a map of strings keeping its keys.
- `agent:eval` command: runs the cases in `evals_dir` with trials (`--trials`, `--min-pass`),
  writes each run to `var/evals/`, and grades a stored run again with `--replay`.
- Eval ports: a tagged `Grader` for a vertical's expected keys, `EvalEnvironment` for the host's
  preconditions and end state, and `EvalIsolation`, which rolls each case back with the ORM.
- `turn_complete` carries `rounds`, the model calls the turn made.

### Changed

- MIT license.
- `Provider\Platform\PlatformProvider` is the shared base of the adapters over a Symfony AI
  platform: it reads the stream, maps errors and reports usage and the stop reason in this
  product's terms; `AnthropicProvider` extends it and only writes the payload.
- `ProviderResponse::$stopReason` is a `StopReason` enum, mapped from the platform's finish reason
  instead of the provider's raw value. The `stop_reason` of events and logs keeps its values.
- Reasoning blocks in the transcript carry the provider that wrote them; an adapter sends back
  its own and leaves another provider's out. A block without the tag is sent as it is.
- `ToolSpec` has no `providerDefinition` nor `isServerTool()`: each adapter writes its tools.
  An empty `properties` in a schema is left out of the request.
- `models` names a platform and a model per role: `turn` (with its `thinking_effort`), `memory`
  and `judge`, each `{platform, model}`; the platform is `anthropic` by default and, like the
  model, can come from an env var. Each role has its provider service
  (`odiseo_ai_conversational_agent.provider.<role>`), picked from the adapters tagged
  `odiseo_ai_conversational_agent.provider`; the `ModelProvider` alias is gone.
- `ModelProvider::capabilities()` takes the model. The Anthropic adapter knows its models: Haiku
  4.5 has no thinking, so its effort is dropped instead of needing `off`; Sonnet 5.5 takes no
  forced tool choice and turns thinking down with `between_tools`; the Claude 5 models take no
  temperature. `capabilities` completes it for a model the adapter does not know yet.
- The loop applies every capability: no cache markers without caching, no thinking without it,
  no eager dispatch without tool-input deltas.
- `prices` sets a model's price, with input, output, cache write and cache read stated apart.
  `ModelPrice` takes all four; the defaults state them for the Claude models. A configured model
  without a price stops the agent from starting instead of costing zero.
- Memory extraction records facts through a `record_fact` tool, as the reference does, and sees
  the facts already saved: a restatement is dropped, an update under a saved key replaces it, and
  at most three are kept per turn. An extraction prompt no longer asks for a JSON array.
- The DBAL stores are gone; the ORM ones replace them.
- `SessionResolver` moved to `Bridge\Symfony\Session`.
- `SkillLoadError` is now `SkillLoadException` and `PresentationRefused` is now
  `PresentationRefusedException`.
- `ODISEO_AGENT_TEST_DATABASE_URL` replaces `AGENT_TEST_DATABASE_URL` for the test suite.
- PHP constraint is `^8.2`.
- `memory.retention_days` defaults to 180 (was null): a fact not updated in that time is no
  longer used, and `agent:prune` deletes it. Set it to null to keep facts.
- The rate limiters are `odiseo_agent_session_start`, `odiseo_agent_chat_turn` and
  `odiseo_agent_chat_turn_per_session` (were `agent_*`).
- `ChipComponent` moved to `Presentation` (was `Execution`).
- `BudgetExceeded` is now `BudgetLimit`.
- `models.thinking_effort` is a string checked when the agent starts (was a list checked when the
  container is built), so it can come from an env var without a default `env()` parameter.
- `EvalRunner` drives a case through `TurnRunner`, so memory extraction and the host's hook run
  as in the chat; a provider error is retried and then reported as an error, not a failure. An
  expected key no grader owns fails the case.
- The judge reads the case's transcript, tool results included, and what each component
  showed; one too long for it loses its oldest messages first, and the verdict says so and
  carries its cost. It sends no temperature, which the Claude 5 models reject, and a judge
  error is a judge failure on the case instead of stopping the run.
- `symfony/ai-platform` is required. The Anthropic adapter is registered when
  `symfony/ai-anthropic-platform` is installed (was when `symfony/ai-platform` was).

### Fixed

- A round cut at the output token limit no longer ends the turn in an error: it closes with
  what arrived and `stop_reason: max_tokens`, logs a warning, and its usage is charged.
- The Anthropic platform needs `cache_retention: none` (README): with the default the bridge adds
  cache breakpoints of its own on top of the bundle's, including on forced-tool rounds, whose
  entries the rounds after them cannot read. The cost is about the same either way; the request
  carries the breakpoints the bundle places, as the reference does.
- An error in an eval case that does not come from the model (a precondition the store cannot
  build, a bug) is an error on that case; the run goes on and its file is written.
- A failed flush inside an eval case no longer leaves the entity manager closed for the cases
  after it.

## [0.1.0] - 2026-09-17

First release: the turn loop with streaming and eager tool dispatch, capabilities, provenance
and grounding gates, presentation components with partial frames, fencing, skills, memory
extraction, budgets, HTTP and console surfaces, and evals.
