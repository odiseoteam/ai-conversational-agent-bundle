<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Eval;

final readonly class EvalResult
{
    /**
     * @param list<string> $failures      one line per assertion that did not hold
     * @param list<string> $judgeFailures a judge that did not return a verdict, kept apart from an agent failure
     */
    public function __construct(
        public EvalCase $case,
        public array $failures = [],
        public array $judgeFailures = [],
        public ?TurnRecording $recording = null,
        public bool $skipped = false,
    ) {
    }

    public function passed(): bool
    {
        return !$this->skipped && [] === $this->failures && [] === $this->judgeFailures;
    }
}
