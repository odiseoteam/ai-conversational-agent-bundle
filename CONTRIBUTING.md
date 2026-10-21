# Contributing

Issues and pull requests are welcome at
[odiseoteam/ai-conversational-agent-bundle](https://github.com/odiseoteam/ai-conversational-agent-bundle).

## Running the checks

```bash
composer install
make check     # php-cs-fixer, phpstan, deptrac, composer-dependency-analyser, phpunit
```

Deptrac keeps the agent free of Symfony and Doctrine: they are reached only through `Bridge/`,
`Provider/Platform/`, `Provider/Anthropic/` and `Provider/OpenAi/`.

The suite runs on SQLite in memory and needs no server. `ODISEO_AGENT_TEST_DATABASE_URL` points the
ORM tests at a real one, which is what CI does for MySQL and PostgreSQL:

```bash
ODISEO_AGENT_TEST_DATABASE_URL=postgresql://user:pass@127.0.0.1:5432/agent_test vendor/bin/phpunit
```
