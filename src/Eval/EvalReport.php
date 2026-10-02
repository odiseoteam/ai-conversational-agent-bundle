<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

use Odiseo\AiConversationalAgentBundle\Support\Scalar;

/**
 * A run's trials per case and what they add up to. A case passes when enough of its trials
 * pass; it is an error when it fell short and some trial never got graded, so a provider
 * outage does not read as the agent failing.
 */
final class EvalReport
{
    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const ERROR = 'error';
    public const SKIPPED = 'skipped';

    /** @param array<string, list<EvalResult>> $trials case id → its trials, in run order */
    public function __construct(
        public readonly array $trials,
        public readonly int $minPass = 1,
    ) {
    }

    public function outcome(string $caseId): string
    {
        $trials = $this->trials[$caseId] ?? [];
        if ([] === $trials || $trials[0]->skipped) {
            return self::SKIPPED;
        }

        $passed = \count(array_filter($trials, static fn (EvalResult $result): bool => $result->passed()));
        if ($passed >= min($this->minPass, \count($trials))) {
            return self::PASSED;
        }

        foreach ($trials as $result) {
            if (null !== $result->error) {
                return self::ERROR;
            }
        }

        return self::FAILED;
    }

    public function passedTrials(string $caseId): int
    {
        return \count(array_filter($this->trials[$caseId] ?? [], static fn (EvalResult $result): bool => $result->passed()));
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $counts = [self::PASSED => 0, self::FAILED => 0, self::ERROR => 0, self::SKIPPED => 0];
        $byPriority = [];
        $cost = 0.0;
        $judgeCost = 0.0;
        $turns = 0;
        $rounds = 0;
        $turnMs = 0;
        $usage = [];

        foreach ($this->trials as $caseId => $results) {
            $outcome = $this->outcome($caseId);
            ++$counts[$outcome];
            if (self::SKIPPED !== $outcome) {
                $priority = $results[0]->case->priority;
                $byPriority[$priority] ??= ['passed' => 0, 'total' => 0];
                ++$byPriority[$priority]['total'];
                $byPriority[$priority]['passed'] += self::PASSED === $outcome ? 1 : 0;
            }

            foreach ($results as $result) {
                $recording = $result->recording;
                if (null === $recording) {
                    continue;
                }
                $cost += $recording->costUsd;
                $judgeCost += Scalar::float($recording->verdict['cost_usd'] ?? null);
                $turns += \count($recording->turnMs);
                $turnMs += array_sum($recording->turnMs);
                $rounds += $recording->rounds;
                foreach ($recording->usage as $key => $tokens) {
                    $usage[$key] = ($usage[$key] ?? 0) + $tokens;
                }
            }
        }

        $read = $usage['cache_read_input_tokens'] ?? 0;
        $prompt = ($usage['input_tokens'] ?? 0) + ($usage['cache_creation_input_tokens'] ?? 0) + $read;

        return [
            'cases' => \count($this->trials),
            ...$counts,
            'by_priority' => $byPriority,
            'cost_usd' => round($cost, 4),
            'judge_cost_usd' => round($judgeCost, 4),
            'turns' => $turns,
            'cost_per_turn_usd' => $turns > 0 ? round($cost / $turns, 4) : 0.0,
            'rounds_per_turn' => $turns > 0 ? round($rounds / $turns, 2) : 0.0,
            'cache_hit_rate' => $prompt > 0 ? round($read / $prompt, 3) : 0.0,
            'avg_turn_ms' => $turns > 0 ? (int) round($turnMs / $turns) : 0,
            'usage' => $usage,
        ];
    }
}
