[← Knowledge](knowledge.md) · [Docs index](../README.md#documentation) · [Logging and retention →](operations.md)

# Runtime changes

What a host lets people change without a deploy (an admin screen, say) reaches the agent
through three ports and a version store. Nothing changes until the host implements one.

- **Settings**: `Config\ConfigOverridesInterface::changes()` returns `AgentConfig` arguments to
  lay over the configuration for the current request: the identity, `model` and `platform`,
  `thinkingEffort`, `memoryModel` and `memoryPlatform`, the budgets. The services that run a turn
  read `Config\AgentConfigProviderInterface` (`RuntimeAgentConfig`), so a model saved at runtime
  takes over on the next turn and the turn's and memory's providers follow its platform. A key
  left out keeps the configured value; a bad value (an unknown effort) is dropped and logged.
- **API keys**: `Provider\ApiKeyResolverInterface::apiKey($platform)` returns another key for a
  platform; the adapter then calls through a platform built like the configured one with that
  key (`KeyedPlatform`). Null keeps the configured key.
- **Spend scope**: `Budget\SpendScopeResolverInterface::scope()` names the part of the deployment
  a call belongs to (one site of several). The ledger records it, and
  `scopeDailyBudgetUsd` caps that scope's day on top of the deployment's day cap.
- **The session in progress**: `Session\CurrentSession` holds the session a turn or a memory
  extraction runs for, so a host's settings can follow it where there is no request (a queue
  worker).
- **Versions**: `Content\VersionStoreInterface` keeps what people edit as versions, by kind and
  key: a draft the agent never reads, publications that are only added, and one "back to the
  default" version that leaves the configured item in force again. With an
  `orm.classes.content_version` entity (extending `Bridge\Doctrine\Model\ContentVersion`) they
  persist; without one they are in memory and only the configuration applies.
  `Rule\VersionedRuleSource` and `Knowledge\VersionedKnowledgeSource` lay the published versions
  of `rule` and `knowledge` over the configured rules and the Markdown files: a version replaces
  the item with its id, a disabled one turns it off, and one that only exists as versions is
  added. The store's revision changes on every publication, and the house rules, the guide index
  and the cached prompt are built again when it does, in every process. Each change costs one
  uncached read of the prompt.
