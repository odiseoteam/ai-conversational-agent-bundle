[← Installation](installation.md) · [Docs index](../README.md#documentation) · [Models and providers →](models.md)

# Concepts and extension points

## Concepts

- **Capability** — a unit of the vertical: tools with JSON schemas, prompt fragments, grounding
  rules and presentation components. Capabilities are autoconfigured
  (`odiseo_ai_conversational_agent.capability`) and assembled by the `CapabilityRegistry`. A
  prompt fragment goes to a section of the cached prompt: How you work, Tools, Presentation,
  Guidance (how the vertical wants requests handled, right before the house rules) or Boundaries.
- **Turn loop** (`AgentLoop`) — streams model rounds and tool calls as `AgentEvent`s; a turn
  ends on text, on a budget limit or on the tool-iteration cap.
- **Gates** — fencing of external text (`Fence`), grounding rules that force a tool when the
  message matches a lexicon, provenance checks and payload guards on what is presented.
- **Sessions and memory** — ORM stores for session state, transcript, long-term facts per
  subject, and a spend ledger; memory extraction runs once the response is out, with a cheaper model
  (in the same process, or in a worker when `ExtractMemory` is routed to a transport).
- **Presentation** — `ui` events carrying components the host renders; the model only names
  them.
- **Rules** — the organisation's own behaviour rules, a "House rules" section of the cached
  prompt; see [Rules](rules.md).
- **Knowledge** — the organisation's policies and guides, searched when the model asks for them;
  see [Knowledge](knowledge.md).
- **Evals** — JSON cases run through the same turn runner as the chat, graded by code and by a
  judge model; see [Evals](evals.md).

## Services

Every service is registered as `odiseo_ai_conversational_agent.*` with explicit arguments.
The classes and ports a host uses are aliases, and a host replaces a port by redefining its
alias: `ContextProviderInterface`, `PrincipalResolverInterface`, `TurnHookInterface`,
`ConsoleEnvironmentInterface`, `ConversationInitializerInterface`, `SessionStore`,
`MemoryStoreInterface`, `SpendLedgerInterface`, `ConfigOverridesInterface`,
`ApiKeyResolverInterface`, `SpendScopeResolverInterface` and `VersionStoreInterface`. Capabilities implement `CapabilityInterface`
and are collected by the `odiseo_ai_conversational_agent.capability` tag; rule sources, knowledge
sources and knowledge searches are collected the same way. A capability that also implements
`ScopedCapabilityInterface` names an area of what the assistant does ("orders"); the prompt's
"Stay within …" line lists the registered areas by priority, so registering a capability widens
it. `identity.audience` completes "talking with …".
