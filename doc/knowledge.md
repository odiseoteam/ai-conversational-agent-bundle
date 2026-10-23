[← Rules](rules.md) · [Docs index](../README.md#documentation) · [Runtime changes →](runtime-changes.md)

# Knowledge

Knowledge is what the organisation knows and the assistant reads when it needs it: policies,
guides, short snippets. A vertical's capability exposes it as a tool and calls `KnowledgeBase`:

```php
$hits = $knowledgeBase->search('can I return an item', 3); // list<KnowledgeHit>, best first
```

Each `KnowledgeHit` carries its `KnowledgeDocument` (`id`, `title`, `kind`, `when`, `url`), the
section's heading and the text for the model. It is the organisation's data: fence it like any
other tool result.

## Files

`knowledge_dir` (null by default, which leaves them out) holds one Markdown file per
topic, with optional front matter:

```markdown
---
id: returns
title: Returns and exchanges
kind: policy          # policy, guide or snippet
when: Returns, refunds and exchanges.
url: /returns
---
# Returns and exchanges

## Can I return an item?

Yes, within 30 days from delivery.
```

Without front matter the file name is the id and the first heading the title; `enabled: false`
leaves the file out. Files are read on every search.

## The lexical search

It splits each document at its `##` headings and ranks the sections of
every source together with BM25. Words are compared without case or accents, without a final
"s" and by their first five letters, so "refunded" finds "refund" and "cafe" finds "café", in any
language; the title and the headings weigh more. A section comes back whole, or as a
3,000-character window around its first match when it is longer. A hit scoring under half the
best one is left out, so a weak match adds no text to the turn.

## Sources and searches

There are two extension points, both autoconfigured:

- `Knowledge\KnowledgeSourceInterface` gives documents (`documents(): iterable`) to the lexical
  search, tagged `odiseo_ai_conversational_agent.knowledge_source`: a database, a CMS, or a
  document the system builds from its own data. This is the usual one.
- `Knowledge\KnowledgeSearchInterface` ranks its own hits (`search($query, $limit)`), tagged
  `odiseo_ai_conversational_agent.knowledge_search`: a search engine such as Elasticsearch, or a
  vector store (`symfony/ai-store` and an embeddings model) for an organisation with a lot of
  content. `KnowledgeBase` merges the searches by position (reciprocal rank fusion), since their
  scores do not compare; with only the lexical search, its order is kept. To use only your own,
  remove the `odiseo_ai_conversational_agent.knowledge.lexical_search` service.

A source or a search that fails is left out and logged on the `conversational_agent` channel, so
a turn never fails over it.

## The guide index

The documents of `kind: guide` from every source are listed in a "# Guides"
section of the cached prompt, after the skills: title and `when`, by title, so the model knows a
guide exists before it searches. They are content, so the list goes inside the fence, sanitized
like any tool result. `guides_notice` is the line above the list (when to read a guide,
and with which tool; a vertical sets it to name its own). At most `limits.max_guides` guides (20)
with a `when` of `limits.max_guide_when_chars` characters (200); one over a cap, or repeating an
id, is left out and logged. Without guides the section is not there. A guide edit changes the
cached prompt, so the next request reads it uncached.
