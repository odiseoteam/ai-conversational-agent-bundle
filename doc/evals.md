[← Logging and retention](operations.md) · [Docs index](../README.md#documentation)

# Evals

A case file in `evals_dir` holds a JSON array of cases, each with a precondition (`state`), the
visitor's `turns` and the `expected` keys it is about; a `rubric` goes to the judge
(`models.judge`), and every other key to a tagged grader. A vertical adds the keys of its own
state with its own `GraderInterface`, and builds the preconditions the core cannot by aliasing
`EvalEnvironmentInterface`. With the ORM each case runs in a transaction that is rolled back, so
nothing it writes stays and its spend does not count against the day's budget.

```bash
bin/console agent:eval                       # every case, once
bin/console agent:eval --priority=critical --trials=3 --min-pass=2
bin/console agent:eval --replay=var/evals/eval-20261002-101500.json
```

A live run calls the configured models and is written to `var/evals/` with its recordings, the
judge's verdicts and the totals: pass rate per priority, cost per turn, rounds per turn, cache
hit rate and turn time. `--replay` grades a stored run again with no model call; a rubric keeps
its stored verdict only while the judge model and the rubric are unchanged.
