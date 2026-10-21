[Docs index](../README.md#documentation) · [Concepts and extension points →](concepts.md)

# Installation

## 1. Require the bundle

```bash
composer require odiseoteam/ai-conversational-agent-bundle
```

Register `Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle` and configure
`odiseo_ai_conversational_agent` (`bin/console config:dump-reference odiseo_ai_conversational_agent`): identity, models,
budgets, memory, fence, rules, sessions, conversations, skills and evals directories.

## 2. Connect a model provider

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

> [!IMPORTANT]
> `cache_retention: none` matters: the bundle places its own cache breakpoints, and with the
> default (`short`) the bridge adds more on top, including on forced-tool rounds, whose entries
> the rounds after them cannot read.

Which model each role runs on, and its price, is in [Models and providers](models.md).

## 3. Import the routes

The agent's HTTP surface (session, chat and memory) comes without a base path. Import it with the
prefix you want, under the firewall that resolves who is asking:

```yaml
odiseo_ai_conversational_agent:
    resource: '@OdiseoAiConversationalAgentBundle/config/routes.yaml'
    prefix: /agent
```

## 4. Map the storage

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
            # Optional: rules and documents edited at runtime (see Runtime changes).
            content_version: App\Entity\AgentContentVersion
```

`doctrine:migrations:diff` then produces the schema: the mappings ship here, the migration
belongs to the application that runs them. Without `doctrine/orm` (or with `orm.enabled: false`)
the stores are in memory and nothing outlives the process.

### Relating a conversation to your schema

A conversation the core creates carries the session id, the principal and the state document.
Anything else the host's schema relates it to it sets through a
`ConversationInitializerInterface`, called on the new entity before it is persisted, while the
request still knows who is asking:

```php
final class LinkTheCustomer implements ConversationInitializerInterface
{
    public function initialize(ConversationInterface $conversation): void
    {
        // $conversation is the host's own entity, still unsaved.
    }
}
```

Point the `ConversationInitializerInterface` alias at it and the core will call it; unset, nothing
happens.

## 5. Add a capability

The agent does nothing until a capability gives it tools. See
[Concepts and extension points](concepts.md), and the example in the
[README](../README.md#quick-example).
