# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Doctrine ORM stores for sessions, memory and spend (`OrmSessionStore`, `OrmMemoryStore`,
  `OrmSpendLedger`) over four mapped superclasses in `Bridge\Doctrine\Model`. The host names its
  entities in `orm.classes`; with `orm.enabled: false` the stores stay in memory.
- `agent:prune` command, with `conversations.retention_days`, `sessions.retention_days` and
  `memory.retention_days`.
- Per-client daily budget (`budgets.client_usd`), keyed by IP.
- `ConversationInitializer`, for the host to fill its own conversation fields when one starts.
- `Scalar::stringMap()`, to narrow a map of strings keeping its keys.
- `agent:eval` command: runs the cases in `evals_dir` with trials (`--trials`, `--min-pass`),
  writes each run to `var/evals/`, and grades a stored run again with `--replay`.
- Eval ports: a tagged `Grader` for a vertical's expected keys, `EvalEnvironment` for the host's
  preconditions and end state, and `EvalIsolation`, which rolls each case back with the ORM.
- `turn_complete` carries `rounds`, the model calls the turn made.

### Changed

- MIT license.
- Memory extraction records facts through a `record_fact` tool, as the reference does, and sees
  the facts already saved: a restatement is dropped, an update under a saved key replaces it, and
  at most three are kept per turn. An extraction prompt no longer asks for a JSON array.
- The DBAL stores are gone; the ORM ones replace them.
- `SessionResolver` moved to `Bridge\Symfony\Session`.
- `SkillLoadError` is now `SkillLoadException` and `PresentationRefused` is now
  `PresentationRefusedException`.
- `ODISEO_AGENT_TEST_DATABASE_URL` replaces `AGENT_TEST_DATABASE_URL` for the test suite.
- PHP constraint is `^8.2`.
- `memory.retention_days` defaults to 180 (was null): a fact not updated in that time is no
  longer used, and `agent:prune` deletes it. Set it to null to keep facts.
- The rate limiters are `odiseo_agent_session_start`, `odiseo_agent_chat_turn` and
  `odiseo_agent_chat_turn_per_session` (were `agent_*`).
- `ChipComponent` moved to `Presentation` (was `Execution`).
- `BudgetExceeded` is now `BudgetLimit`.
- `models.thinking_effort` is a string checked when the agent starts (was a list checked when the
  container is built), so it can come from an env var without a default `env()` parameter.
- `EvalRunner` drives a case through `TurnRunner`, so memory extraction and the host's hook run
  as in the chat; a provider error is retried and then reported as an error, not a failure. An
  expected key no grader owns fails the case.
- The judge reads the case's transcript, tool results included, and what each component
  showed; one too long for it loses its oldest messages first, and the verdict says so and
  carries its cost. It sends no temperature, which the Claude 5 models reject, and a judge
  error is a judge failure on the case instead of stopping the run.
- `symfony/ai-platform` is required. The Anthropic adapter is registered when
  `symfony/ai-anthropic-platform` is installed (was when `symfony/ai-platform` was).

## [0.1.0] - 2026-09-17

First release: the turn loop with streaming and eager tool dispatch, capabilities, provenance
and grounding gates, presentation components with partial frames, fencing, skills, memory
extraction, budgets, HTTP and console surfaces, and evals.
