# AI Agent Bundle

A vertical-agnostic core for conversational agents in Symfony: the turn loop, tool
execution, gates, sessions, streaming, memory, budgets and evals. Verticals (a shopping
assistant, an institutional assistant) are built on top of it as capabilities.

It ports the `commerce_common` layer of Anthropic's
[commerce-agents](https://github.com/anthropics/commerce-agents) reference to PHP; nothing in
it knows about commerce.

## Concepts

- **Capability** — a unit of the vertical: tools with JSON schemas, prompt fragments, grounding
  rules and presentation components. Capabilities are autoconfigured
  (`odiseo_ai_conversational_agent.capability`) and assembled by the `CapabilityRegistry`.
- **Turn loop** (`AgentLoop`) — streams model rounds and tool calls as `AgentEvent`s; a turn
  ends on text, on a budget limit or on the tool-iteration cap.
- **Gates** — fencing of external text (`Fence`), grounding rules that force a tool when the
  message matches a lexicon, provenance checks and payload guards on what is presented.
- **Sessions and memory** — DBAL stores for session state, transcript, long-term facts per
  subject, and a spend ledger; memory extraction runs after the turn with a cheaper model.
- **Presentation** — `ui` events carrying components the host renders; the model only names
  them.
- **Handoff** — `request_human_help` opens a request on a `HandoffChannel` (a mailbox, a
  ticketing system, a webhook, a live desk); the record is kept in a `HandoffStore` and the
  `handoff` component shows the reference. Off until `handoff.enabled` is set; the default
  channel only logs, a deployment names its own in `handoff.channel`.
- **Evals** — YAML cases run against a scripted or live provider with a judge model.

## Installation

```bash
composer require odiseoteam/ai-conversational-agent-bundle
```

Register `Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle` and configure
`odiseo_ai_conversational_agent` (`bin/console config:dump-reference odiseo_ai_conversational_agent`): identity, models,
budgets, memory, fence, sessions, skills and evals directories. The Anthropic provider needs
`symfony/ai-anthropic-platform`; the DBAL stores need `doctrine/dbal` and the tables
`agent_session_state`, `agent_session_message`, `agent_memory_fact`, `agent_spend_ledger`,
`agent_handoff`.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## License

Proprietary, see `LICENSE`.
