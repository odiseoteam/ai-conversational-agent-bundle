# AI Conversational Agent Bundle

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
budgets, memory, fence, sessions, conversations, skills and evals directories. The Anthropic provider needs
`symfony/ai-anthropic-platform`.

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
installed; without it, alias `ModelProvider` to your own provider. Capabilities are collected
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

## License

Proprietary, see `LICENSE`.
