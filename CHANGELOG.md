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

### Changed

- MIT license.
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
- `models.thinking_effort` is a string checked when the agent starts (was a list checked when the
  container is built), so it can come from an env var without a default `env()` parameter.

## [0.1.0] - 2026-09-17

First release: the turn loop with streaming and eager tool dispatch, capabilities, provenance
and grounding gates, presentation components with partial frames, fencing, skills, memory
extraction, budgets, HTTP and console surfaces, and evals.
