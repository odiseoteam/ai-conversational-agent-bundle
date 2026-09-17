<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Eval;

use Odiseo\AiAgentBundle\Agent\AgentLoop;
use Odiseo\AiAgentBundle\Agent\Transcript;
use Odiseo\AiAgentBundle\Eval\Grader\CodeGrader;
use Odiseo\AiAgentBundle\Eval\Grader\JudgeGrader;
use Odiseo\AiAgentBundle\Memory\MemoryCategory;
use Odiseo\AiAgentBundle\Memory\MemoryFact;
use Odiseo\AiAgentBundle\Memory\MemoryStore;
use Odiseo\AiAgentBundle\Session\SeenRecord;
use Odiseo\AiAgentBundle\Session\SessionContext;
use Odiseo\AiAgentBundle\Session\TurnState;

/**
 * Runs a case: loads its precondition into the session state and the memory store, plays its
 * turns, and grades what came back.
 *
 * Each case gets a fresh session and a cleared memory subject, so a case never inherits state
 * from the one before it.
 */
final class EvalRunner
{
    public function __construct(
        private readonly AgentLoop $loop,
        private readonly MemoryStore $memory,
        private readonly CodeGrader $grader = new CodeGrader(),
        private readonly ?JudgeGrader $judge = null,
        private readonly string $timezone = 'America/Argentina/Buenos_Aires',
    ) {
    }

    public function run(EvalCase $case): EvalResult
    {
        if (null !== $case->skip) {
            return new EvalResult($case, skipped: true);
        }

        $principal = 'eval-'.substr(hash('sha256', $case->id), 0, 12);
        $this->memory->clear($principal);

        $state = new TurnState();
        $this->seed($case, $state, $principal);

        $session = new SessionContext(
            sessionId: 'eval-'.bin2hex(random_bytes(8)),
            principalId: $principal,
            timezone: $this->timezone,
        );

        $recording = new TurnRecording();
        $messages = [];

        foreach ($case->turns as $turn) {
            $messages[] = Transcript::userMessage($turn);
            foreach ($this->loop->streamTurn($messages, $session, $state) as $event) {
                $recording->record($event);
            }
        }

        $failures = $this->grader->grade($case, $recording, $this->memory->all($principal));
        $judgeFailures = [];

        if (null !== $this->judge && isset($case->expected['rubric'])) {
            $verdict = $this->judge->judge($case, $recording);
            if (null === $verdict) {
                $judgeFailures[] = 'the judge did not return a verdict';
            } elseif ('FAIL' === $verdict['verdict']) {
                $failures[] = 'rubric: '.$verdict['reason'];
            }
        }

        return new EvalResult($case, $failures, $judgeFailures, $recording);
    }

    /** @return list<EvalResult> */
    public function runSuite(EvalSuite $suite): array
    {
        return array_map($this->run(...), $suite->cases);
    }

    private function seed(EvalCase $case, TurnState $state, string $principal): void
    {
        foreach (\is_array($case->state['seen_records'] ?? null) ? $case->state['seen_records'] : [] as $record) {
            if (\is_string($record)) {
                $state->remember(new SeenRecord($record, 'record'));
            } elseif (\is_array($record)) {
                $state->remember(SeenRecord::fromArray($record));
            }
        }

        foreach (\is_array($case->state['vertical'] ?? null) ? $case->state['vertical'] : [] as $key => $value) {
            $state->set((string) $key, $value);
        }

        foreach (\is_array($case->state['memory'] ?? null) ? $case->state['memory'] : [] as $fact) {
            if (!\is_array($fact) || !isset($fact['key'], $fact['value'])) {
                continue;
            }

            $this->memory->save($principal, new MemoryFact(
                (string) $fact['key'],
                (string) $fact['value'],
                MemoryCategory::tryFrom((string) ($fact['category'] ?? '')) ?? MemoryCategory::Preference,
                new \DateTimeImmutable(),
                'seeded',
            ));
        }
    }
}
