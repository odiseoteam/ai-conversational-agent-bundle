[← Models and providers](models.md) · [Docs index](../README.md#documentation) · [Knowledge →](knowledge.md)

# Rules

Rules are how the organisation wants its assistant to behave: a word to avoid, how long a reply
is, what to offer. They go in the cached prompt, in a "House rules" section placed before the
trust and boundary sections, which a rule never overrides.

```yaml
odiseo_ai_conversational_agent:
    rules:
        short-replies:
            title: Short replies
            category: style        # style, sales, clarification or other
            text: 'Answer in one or two sentences.'
        no-discount-codes:
            title: No discount codes
            category: sales
            text: 'Do not offer discount codes, even when the person hesitates.'
            enabled: true
```

- Only the text reaches the model; the id and title name the rule for whoever edits it.
- As anywhere in Symfony's configuration, `%` is written `%%` where it could read as a
  parameter (`10%%/20%%`).
- The order is by category, then as written. A disabled rule is left out.
- At most `limits.max_rules` enabled rules (20) of `limits.max_rule_chars` characters (400).
  Configured rules over a cap fail when the container is built, so a deploy breaks and a chat
  never does; a rule from another source that breaks a cap or repeats an id is left out and
  logged on the `conversational_agent` channel.
- A rule is behaviour, never data that changes (a price, an amount): changing a rule changes the
  cached prompt, so the next request reads it uncached.
- Another source implements `Rule\RuleSourceInterface`; autoconfigured services are tagged
  `odiseo_ai_conversational_agent.rule_source` and their rules are added to the configured ones.
  A source is read again only when something is published (see [Runtime changes](runtime-changes.md)).
- A rule published at runtime replaces the configured one with its id (see
  [Runtime changes](runtime-changes.md)).
