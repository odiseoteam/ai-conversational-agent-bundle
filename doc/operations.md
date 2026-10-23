[← Runtime changes](runtime-changes.md) · [Docs index](../README.md#documentation) · [Evals →](evals.md)

# Logging and retention

## Logging

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

## Retention

Two clocks. `sessions.retention_days` (30) is how long a session is served after its last
activity; past it the widget starts a new one, but the conversation stays for the host to read.
`conversations.retention_days` (180) is how long it is kept; `memory.retention_days` (180)
does the same for memory facts, counted from their last update, and a fact past it is no longer
used even before it is pruned. Null keeps them. A host that sets the memory retention at runtime
says which one pruning uses (`Memory\MemoryRetentionInterface`). `bin/console agent:prune` applies both,
dropping the conversations with their messages and spend; `--dry-run` only counts. Run it
from a daily cron.
