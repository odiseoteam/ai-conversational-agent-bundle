[← Concepts and extension points](concepts.md) · [Docs index](../README.md#documentation) · [Rules →](rules.md)

# Models and providers

## A model per role

Each role (the turn, the memory extraction, the eval judge) names its platform and model; both
can come from env vars, because a role's provider is picked when its service is built:

```yaml
odiseo_ai_conversational_agent:
    models:
        turn: { platform: '%env(AGENT_TURN_PLATFORM)%', model: '%env(AGENT_TURN_MODEL)%', thinking_effort: low }
        memory: { platform: anthropic, model: claude-haiku-5-5 }
        judge: { platform: anthropic, model: claude-sonnet-5-5 }
```

Without them, the turn and the memory run on Claude Haiku 5.5 (the turn at `low` effort) and the
judge on Claude Sonnet 5.5.

## Platforms

A platform is an adapter tagged `odiseo_ai_conversational_agent.provider` with its `platform`
name. `anthropic` and `openai` are registered when their Symfony AI bridge is installed and wrap
the AI bundle's `ai.platform.<name>`; your own adapter extends `PlatformProvider` or implements
`ModelProviderInterface`, and is tagged the same way. The conversation, its tool results and the
memory go to the provider each role is on. To replace one role's provider outright, redefine
`odiseo_ai_conversational_agent.provider.<role>`.

## Prices and capabilities

Every configured model needs a price, or the agent refuses to start: an unpriced model would
cost zero and no spend cap would stop it. The Claude models carry theirs; add or correct one
under `prices`, in USD per million tokens. An adapter knows what each of its models can do (a
forced tool choice, thinking, a temperature…); for a model it does not know yet, say so under
`capabilities`. A model the AI bundle's catalog does not list yet is declared under its
`ai.model.<platform>`; the bundle does that itself for Claude Haiku 5.5 and Sonnet 5.5 until the
catalog lists them:

```yaml
odiseo_ai_conversational_agent:
    prices:
        claude-sonnet-6: { input: 2.0, output: 10.0, cache_write: 2.5, cache_read: 0.2 }
    capabilities:
        claude-sonnet-6: { forced_tool_choice: false }
```

Some models return a longer note written before a tool call hidden inside a thinking block
(`text_before_tool_calls: false`; Claude Sonnet 5.5, Opus 5.5 and Fable 5 by default). Since a
turn without a card ends with the chips, those models write the reply in the chips tool's
`reply` field, and it is shown as text while it streams.
