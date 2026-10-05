<h1 align="center">AI Conversational Agent Bundle</h1>

<p align="center">
    <a href="https://packagist.org/packages/odiseoteam/ai-conversational-agent-bundle"><img src="https://img.shields.io/packagist/v/odiseoteam/ai-conversational-agent-bundle.svg?style=flat-square" alt="Version" /></a>
    <a href="https://packagist.org/packages/odiseoteam/ai-conversational-agent-bundle"><img src="https://img.shields.io/packagist/dt/odiseoteam/ai-conversational-agent-bundle.svg?style=flat-square" alt="Downloads" /></a>
    <a href="https://github.com/odiseoteam/ai-conversational-agent-bundle/actions/workflows/build.yml"><img src="https://img.shields.io/github/actions/workflow/status/odiseoteam/ai-conversational-agent-bundle/build.yml?branch=master&style=flat-square" alt="Build status" /></a>
    <img src="https://img.shields.io/badge/symfony-7.3%20%7C%207.4%20%7C%208.x-1abb9c?style=flat-square" alt="Symfony versions" />
    <img src="https://img.shields.io/packagist/dependency-v/odiseoteam/ai-conversational-agent-bundle/php?style=flat-square" alt="PHP version" />
    <a href="LICENSE"><img src="https://img.shields.io/packagist/l/odiseoteam/ai-conversational-agent-bundle.svg?style=flat-square" alt="License" /></a>
</p>

---

A vertical-agnostic core for conversational agents in Symfony: the turn loop, tool
execution, gates, sessions, streaming, memory, budgets and evals. Verticals (a shopping
assistant, an institutional assistant) are built on top of it as capabilities.

It ports the `commerce_common` layer of Anthropic's
[commerce-agents](https://github.com/anthropics/commerce-agents) reference to PHP; nothing in
it knows about commerce.

## What you get

- **A streaming turn loop**: Model rounds, tool calls and UI components reach the browser as
  server-sent events while the model is still writing.
- **Tools the model can't misuse**: JSON-schema validation, provenance checks (it can only act on
  ids a tool returned) and grounding rules that force a lookup before it answers.
- **Prompt-injection fencing**: Site content and tool results are quoted, never read as
  instructions.
- **Memory across sessions**: Facts about the visitor, extracted after the turn by a cheaper
  model, with a filter that keeps identifiers and credentials out.
- **Budgets and costs**: Spend limits per session, per client and per day, rate limits on the
  endpoints, and a ledger of what every turn spent.
- **Persistence on Doctrine**: Conversations, transcripts, memory and spend, with retention and a
  prune command.
- **Evals**: JSON cases run against the live model, graded by code and by a judge, with trials
  and a replay that grades a stored run again.

## Concepts

- **Capability** — a unit of the vertical: tools with JSON schemas, prompt fragments, grounding
  rules and presentation components. Capabilities are autoconfigured
  (`odiseo_ai_conversational_agent.capability`) and assembled by the `CapabilityRegistry`.
- **Turn loop** (`AgentLoop`) — streams model rounds and tool calls as `AgentEvent`s; a turn
  ends on text, on a budget limit or on the tool-iteration cap.
- **Gates** — fencing of external text (`Fence`), grounding rules that force a tool when the
  message matches a lexicon, provenance checks and payload guards on what is presented.
- **Sessions and memory** — ORM stores for session state, transcript, long-term facts per
  subject, and a spend ledger; memory extraction runs once the response is out, with a cheaper model
  (in the same process, or in a worker when `ExtractMemory` is routed to a transport).
- **Presentation** — `ui` events carrying components the host renders; the model only names
  them.
- **Evals** — JSON cases run through the same turn runner as the chat, graded by code and by a
  judge model.

## Installation

```bash
composer require odiseoteam/ai-conversational-agent-bundle
```

Register `Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle` and configure
`odiseo_ai_conversational_agent` (`bin/console config:dump-reference odiseo_ai_conversational_agent`): identity, models,
budgets, memory, fence, sessions, conversations, skills and evals directories.

For Claude, install `symfony/ai-bundle` and `symfony/ai-anthropic-platform`, register `Symfony\AI\AiBundle\AiBundle`
(Flex does it) and configure the platform; the bundle wraps its `ai.platform.anthropic` service.
For OpenAI, the same with `symfony/ai-open-ai-platform` and `ai.platform.openai` (`api_key`
only: its caching is automatic). Both can be installed, each role on either:

```yaml
ai:
    platform:
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
            cache_retention: none
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
```

`cache_retention: none` matters: the bundle places its own cache breakpoints, and with the
default (`short`) the bridge adds more on top, including on forced-tool rounds, whose entries
the rounds after them cannot read.

### Storage

Sessions, transcripts, memory facts and the spend ledger persist through Doctrine ORM, on any
platform it supports. The stores work against the four interfaces in `Bridge\Doctrine\Model`
(`ConversationInterface`, `MessageInterface`, `MemoryFactInterface`, `SpendEntryInterface`) and
never name a concrete class. Beside each one is a mapped superclass implementing it, with its
XML mapping; the host extends that, names the table and adds whatever it relates to, and points
the bundle at its classes:

```php
#[ORM\Entity]
#[ORM\Table(name: 'app_agent_conversation')]
class AgentConversation extends \Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\Conversation
{
}

#[ORM\Entity]
#[ORM\Table(name: 'app_agent_message')]
class AgentMessage extends \Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\Message
{
    #[ORM\ManyToOne(targetEntity: AgentConversation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?\Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\ConversationInterface $conversation = null;
}
```

```yaml
odiseo_ai_conversational_agent:
    orm:
        classes:
            conversation: App\Entity\AgentConversation
            message: App\Entity\AgentMessage
            memory_fact: App\Entity\AgentMemoryFact
            spend_entry: App\Entity\AgentSpendEntry
```

A conversation the core creates carries the session id, the principal and the state document.
Anything else the host's schema relates it to it sets through a `ConversationInitializer`,
called on the new entity before it is persisted, while the request still knows who is asking:

```php
final class LinkTheCustomer implements ConversationInitializer
{
    public function initialize(ConversationInterface $conversation): void
    {
        // $conversation is the host's own entity, still unsaved.
    }
}
```

Point the `ConversationInitializer` alias at it and the core will call it; unset, nothing happens.

### Services

Every service is registered as `odiseo_ai_conversational_agent.*` with explicit arguments.
The classes and ports a host uses are aliases, and a host replaces a port by redefining its
alias: `ContextProvider`, `PrincipalResolver`, `TurnHook`, `ConsoleEnvironment`,
`ConversationInitializer`, `SessionStore`, `MemoryStore` and `SpendLedger`. Capabilities are
collected by the `odiseo_ai_conversational_agent.capability` tag.

### Logging

The loop and its services log to the `conversational_agent` channel: one `model call` line per
round, with the session, the model, the usage and the tools called, plus the warnings and
errors of a turn. The handlers are the application's. To keep the channel in its own file and
out of the main one:

```yaml
monolog:
    handlers:
        main:
            channels: ['!event', '!conversational_agent']
        conversational_agent:
            type: rotating_file
            path: '%kernel.logs_dir%/conversational_agent.log'
            level: info
            date_format: 'Y-m'
            max_files: 12
            channels: [conversational_agent]
```

A `fingers_crossed` main handler only writes the `info` lines of a request that failed, so
without a handler of its own the `model call` lines of a production turn are not kept.

### Models

Each role (the turn, the memory extraction, the eval judge) names its platform and model; both
can come from env vars, because a role's provider is picked when its service is built:

```yaml
odiseo_ai_conversational_agent:
    models:
        turn: { platform: '%env(AGENT_TURN_PLATFORM)%', model: '%env(AGENT_TURN_MODEL)%', thinking_effort: low }
        memory: { platform: anthropic, model: claude-haiku-4-5-20251001 }
        judge: { platform: anthropic, model: claude-sonnet-5 }
```

A platform is an adapter tagged `odiseo_ai_conversational_agent.provider` with its `platform`
name. `anthropic` and `openai` are registered when their Symfony AI bridge is installed and wrap
the AI bundle's `ai.platform.<name>`; your own adapter extends `PlatformProvider` or implements
`ModelProvider`, and is tagged the same way. The conversation, its tool results and the memory
go to the provider each role is on. To replace one role's provider outright, redefine
`odiseo_ai_conversational_agent.provider.<role>`.

Every configured model needs a price, or the agent refuses to start: an unpriced model would
cost zero and no spend cap would stop it. The Claude models carry theirs; add or correct one
under `prices`, in USD per million tokens. An adapter knows what each of its models can do (a
forced tool choice, thinking, a temperature…); for a model it does not know yet, say so under
`capabilities`. A model the AI bundle's catalog does not list yet is declared under its
`ai.model.<platform>`:

```yaml
odiseo_ai_conversational_agent:
    prices:
        claude-sonnet-6: { input: 2.0, output: 10.0, cache_write: 2.5, cache_read: 0.2 }
    capabilities:
        claude-sonnet-6: { forced_tool_choice: false }
```

`doctrine:migrations:diff` then produces the schema: the mappings ship here, the migration
belongs to the application that runs them. Without `doctrine/orm` (or with `orm.enabled: false`)
the stores are in memory and nothing outlives the process.

### Retention

Two clocks. `sessions.retention_days` (30) is how long a session is served after its last
activity; past it the widget starts a new one, but the conversation stays for the host to read.
`conversations.retention_days` (180) is how long it is kept; `memory.retention_days` (180)
does the same for memory facts, counted from their last update, and a fact past it is no longer
used even before it is pruned. Null keeps them. `bin/console agent:prune` applies both,
dropping the conversations with their messages and spend; `--dry-run` only counts. Run it
from a daily cron.

### Evals

A case file in `evals_dir` holds a JSON array of cases, each with a precondition (`state`), the
visitor's `turns` and the `expected` keys it is about; a `rubric` goes to the judge
(`models.judge`), and every other key to a tagged grader. A vertical adds
the keys of its own state with its own `Grader`, and builds the preconditions the core cannot
by aliasing `EvalEnvironment`. With the ORM each case runs in a transaction that is rolled back,
so nothing it writes stays and its spend does not count against the day's budget.

```bash
bin/console agent:eval                       # every case, once
bin/console agent:eval --priority=critical --trials=3 --min-pass=2
bin/console agent:eval --replay=var/evals/eval-20261002-101500.json
```

A live run calls the configured models and is written to `var/evals/` with its recordings, the
judge's verdicts and the totals: pass rate per priority, cost per turn, rounds per turn, cache
hit rate and turn time. `--replay` grades a stored run again with no model call; a rubric keeps
its stored verdict only while the judge model and the rubric are unchanged.

## Compatibility

| Bundle | PHP             | Symfony         | Database                             | Model                                  |
| ------ | --------------- | --------------- | ------------------------------------ | -------------------------------------- |
| `0.x`  | 8.2 · 8.3 · 8.4 | 7.3 · 7.4 · 8.x | Any Doctrine ORM platform; CI covers SQLite, MySQL and PostgreSQL | Claude, through Symfony AI; or your own `ModelProvider` |

The bundle is in `0.x`: minor versions may break the API until `1.0`.

## Development

```bash
composer install
make check     # php-cs-fixer, phpstan, deptrac, composer-dependency-analyser, phpunit
```

Deptrac keeps the agent free of Symfony and Doctrine: they are reached only through `Bridge/`, `Provider/Platform/`
and `Provider/Anthropic/`.

The suite runs on SQLite in memory and needs no server. `ODISEO_AGENT_TEST_DATABASE_URL` points the
ORM tests at a real one, which is what CI does for MySQL and PostgreSQL:

```bash
ODISEO_AGENT_TEST_DATABASE_URL=postgresql://user:pass@127.0.0.1:5432/agent_test vendor/bin/phpunit
```

## Demo

Want a live walkthrough of this bundle? [Get in touch](https://odiseo.io/en/contact-us?utm_source=github&utm_medium=readme&utm_campaign=ai-conversational-agent-bundle), or see what else we build at [odiseo.io](https://odiseo.io/en?utm_source=github&utm_medium=readme&utm_campaign=ai-conversational-agent-bundle).

## Credits

This bundle is maintained by [Odiseo](https://odiseo.io/en?utm_source=github&utm_medium=readme&utm_campaign=ai-conversational-agent-bundle). Want us to help you build an agent on it, or with any Symfony or Sylius project? [Get in touch](https://odiseo.io/en/contact-us?utm_source=github&utm_medium=readme&utm_campaign=ai-conversational-agent-bundle).

## License

[MIT](LICENSE).
