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
- **Evals**: YAML cases run against a scripted or live model, graded by code and by a judge.

## Concepts

- **Capability** — a unit of the vertical: tools with JSON schemas, prompt fragments, grounding
  rules and presentation components. Capabilities are autoconfigured
  (`odiseo_ai_conversational_agent.capability`) and assembled by the `CapabilityRegistry`.
- **Turn loop** (`AgentLoop`) — streams model rounds and tool calls as `AgentEvent`s; a turn
  ends on text, on a budget limit or on the tool-iteration cap.
- **Gates** — fencing of external text (`Fence`), grounding rules that force a tool when the
  message matches a lexicon, provenance checks and payload guards on what is presented.
- **Sessions and memory** — ORM stores for session state, transcript, long-term facts per
  subject, and a spend ledger; memory extraction runs after the turn with a cheaper model.
- **Presentation** — `ui` events carrying components the host renders; the model only names
  them.
- **Evals** — YAML cases run against a scripted or live provider with a judge model.

## Installation

```bash
composer require odiseoteam/ai-conversational-agent-bundle
```

Register `Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle` and configure
`odiseo_ai_conversational_agent` (`bin/console config:dump-reference odiseo_ai_conversational_agent`): identity, models,
budgets, memory, fence, sessions, conversations, skills and evals directories.

For Claude, install `symfony/ai-bundle` and `symfony/ai-anthropic-platform`, register `Symfony\AI\AiBundle\AiBundle`
(Flex does it) and configure the platform; the bundle wraps its `ai.platform.anthropic` service:

```yaml
ai:
    platform:
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
```

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
alias: `ModelProvider`, `ContextProvider`, `PrincipalResolver`, `TurnHook`,
`ConsoleEnvironment`, `ConversationInitializer`, `SessionStore`, `MemoryStore` and
`SpendLedger`. The Anthropic adapter is registered only when `symfony/ai-anthropic-platform` is
installed, and it needs the AI bundle's `ai.platform.anthropic`; without them, alias
`ModelProvider` to your own provider. Capabilities are collected
by the `odiseo_ai_conversational_agent.capability` tag.

`doctrine:migrations:diff` then produces the schema: the mappings ship here, the migration
belongs to the application that runs them. Without `doctrine/orm` (or with `orm.enabled: false`)
the stores are in memory and nothing outlives the process.

### Retention

Two clocks. `sessions.retention_days` (30) is how long a session is served after its last
activity; past it the widget starts a new one, but the conversation stays for the host to read.
`conversations.retention_days` (180) is how long it is kept; `memory.retention_days` (null)
does the same for memory facts. Null keeps them. `bin/console agent:prune` applies both,
dropping the conversations with their messages and spend; `--dry-run` only counts. Run it
from a daily cron.

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

Deptrac keeps the agent free of Symfony and Doctrine: they are reached only through `Bridge/`
and `Provider/Anthropic/`.

The suite runs on SQLite in memory and needs no server. `AGENT_TEST_DATABASE_URL` points the
ORM tests at a real one, which is what CI does for MySQL and PostgreSQL:

```bash
AGENT_TEST_DATABASE_URL=postgresql://user:pass@127.0.0.1:5432/agent_test vendor/bin/phpunit
```

## Demo

Want a live walkthrough of this bundle? [Get in touch](https://odiseo.io/en/contact-us?utm_source=github&utm_medium=readme&utm_campaign=ai-conversational-agent-bundle), or see what else we build at [odiseo.io](https://odiseo.io/en?utm_source=github&utm_medium=readme&utm_campaign=ai-conversational-agent-bundle).

## Credits

This bundle is maintained by [Odiseo](https://odiseo.io/en?utm_source=github&utm_medium=readme&utm_campaign=ai-conversational-agent-bundle). Want us to help you build an agent on it, or with any Symfony or Sylius project? [Get in touch](https://odiseo.io/en/contact-us?utm_source=github&utm_medium=readme&utm_campaign=ai-conversational-agent-bundle).

## License

[MIT](LICENSE).
