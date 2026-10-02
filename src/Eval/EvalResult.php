<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

final readonly class EvalResult
{
    /**
     * @param list<string> $failures      one line per assertion that did not hold
     * @param list<string> $judgeFailures a judge that did not return a verdict, kept apart from an agent failure
     * @param string|null  $error         the run itself failed (the provider, after its retries); nothing was graded
     */
    public function __construct(
        public EvalCase $case,
        public array $failures = [],
        public array $judgeFailures = [],
        public ?TurnRecording $recording = null,
        public bool $skipped = false,
        public ?string $error = null,
    ) {
    }

    public function passed(): bool
    {
        return !$this->skipped && null === $this->error && [] === $this->failures && [] === $this->judgeFailures;
    }
}
