<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Command;

use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Eval\EvalReport;
use Odiseo\AiConversationalAgentBundle\Eval\EvalResult;
use Odiseo\AiConversationalAgentBundle\Eval\EvalRunner;
use Odiseo\AiConversationalAgentBundle\Eval\EvalSuite;
use Odiseo\AiConversationalAgentBundle\Eval\TurnRecording;
use Odiseo\AiConversationalAgentBundle\Host\ConsoleEnvironment;
use Odiseo\AiConversationalAgentBundle\Provider\AuthenticationException;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the eval cases against the configured model, or grades a stored run again without one.
 * A live run is written to JSON (the recordings, the verdicts and the totals) so it can be
 * replayed and compared; which model it measured is whatever the agent is configured with.
 */
#[AsCommand(name: 'agent:eval', description: 'Run the eval cases, or grade a stored run again')]
final class EvalCommand extends Command
{
    public function __construct(
        private readonly EvalRunner $runner,
        private readonly ConsoleEnvironment $environment,
        private readonly AgentConfig $config,
        private readonly ?string $evalsDir,
        private readonly string $runsDir,
        private readonly string $judgeModel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory of case files (defaults to evals_dir)')
            ->addOption('priority', null, InputOption::VALUE_REQUIRED, 'Only cases of this priority')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only cases carrying this tag')
            ->addOption('case', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this case id')
            ->addOption('trials', null, InputOption::VALUE_REQUIRED, 'Runs per case', '1')
            ->addOption('min-pass', null, InputOption::VALUE_REQUIRED, 'Trials a case must pass (defaults to all)')
            ->addOption('replay', null, InputOption::VALUE_REQUIRED, 'Grade this stored run again, with no model call')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Where to write the run (a live run defaults to a file in the runs directory)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = Scalar::nullableString($input->getOption('dir')) ?? $this->evalsDir;
        if (null === $dir || !is_dir($dir)) {
            $io->error('No eval cases: set evals_dir or pass --dir.');

            return Command::FAILURE;
        }

        $suite = EvalSuite::fromDirectory($dir)->filter(
            Scalar::nullableString($input->getOption('priority')),
            Scalar::strings($input->getOption('tag')),
            Scalar::strings($input->getOption('case')),
        );
        if ([] === $suite->cases) {
            $io->error('No case matches.');

            return Command::FAILURE;
        }

        $replay = Scalar::nullableString($input->getOption('replay'));
        $trials = max(1, Scalar::int($input->getOption('trials'), 1));
        $minPass = Scalar::int($input->getOption('min-pass'), $trials);

        try {
            [$byCase, $meta] = null === $replay
                ? $this->live($suite, $trials, $io)
                : $this->replay($suite, $replay, $io);
        } catch (AuthenticationException $rejected) {
            $io->error('The model credential was rejected: '.$rejected->getMessage());

            return Command::FAILURE;
        } catch (\RuntimeException $failed) {
            $io->error($failed->getMessage());

            return Command::FAILURE;
        }

        $report = new EvalReport($byCase, null === $replay ? $minPass : Scalar::int($meta['min_pass'] ?? null, $minPass));
        $this->render($io, $report);

        $file = Scalar::nullableString($input->getOption('output'))
            ?? (null === $replay ? $this->runsDir.'/eval-'.date('Ymd-His').'.json' : null);
        if (null !== $file) {
            $this->write($file, $report, $meta);
            $io->writeln('Run written to '.$file);
        }

        $summary = $report->summary();

        return 0 === $summary[EvalReport::FAILED] && 0 === $summary[EvalReport::ERROR] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return array{array<string, list<EvalResult>>, array<string, mixed>} */
    private function live(EvalSuite $suite, int $trials, SymfonyStyle $io): array
    {
        $this->environment->prepare();
        $byCase = [];
        foreach ($suite->cases as $case) {
            $io->write(\sprintf('%s ', $case->id));
            for ($trial = 0; $trial < $trials; ++$trial) {
                $result = $this->runner->run($case);
                $byCase[$case->id][] = $result;
                $io->write(match (true) {
                    $result->skipped => 's',
                    null !== $result->error => 'E',
                    $result->passed() => '.',
                    default => 'F',
                });
                if ($result->skipped) {
                    break;
                }
            }
            $io->newLine();
        }

        return [$byCase, [
            'model' => $this->config->model,
            'thinking_effort' => $this->config->thinkingEffort->value ?? 'off',
            'memory_model' => $this->config->memoryModel,
            'judge_model' => $this->judgeModel,
            'trials' => $trials,
        ]];
    }

    /** @return array{array<string, list<EvalResult>>, array<string, mixed>} */
    private function replay(EvalSuite $suite, string $file, SymfonyStyle $io): array
    {
        $stored = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!\is_array($stored) || !\is_array($stored['results'] ?? null)) {
            throw new \RuntimeException(\sprintf('%s is not a stored eval run.', $file));
        }

        $byCase = [];
        foreach (Scalar::rows($stored['results']) as $row) {
            $case = $suite->find(Scalar::string($row['case'] ?? null));
            if (null === $case) {
                continue;
            }
            $recording = \is_array($row['recording'] ?? null) ? TurnRecording::fromArray(Scalar::keyed($row['recording'])) : null;
            $error = Scalar::nullableString($row['error'] ?? null);
            $byCase[$case->id][] = null === $recording
                ? new EvalResult($case, skipped: null !== $case->skip, error: null !== $case->skip ? null : ($error ?? 'not recorded'))
                : $this->runner->regrade($case, $recording);
        }
        $io->writeln(\sprintf('Replayed %d of the %d cases selected from %s.', \count($byCase), \count($suite->cases), $file));

        return [$byCase, Scalar::keyed($stored['meta'] ?? null)];
    }

    private function render(SymfonyStyle $io, EvalReport $report): void
    {
        $rows = [];
        foreach ($report->trials as $caseId => $results) {
            $outcome = $report->outcome($caseId);
            $failing = array_values(array_filter($results, static fn (EvalResult $result): bool => !$result->passed() && !$result->skipped));
            $detail = [] === $failing ? '' : implode('; ', $failing[0]->error ? [$failing[0]->error] : [...$failing[0]->failures, ...$failing[0]->judgeFailures]);
            $rows[] = [
                $caseId,
                $results[0]->case->priority,
                strtoupper($outcome),
                EvalReport::SKIPPED === $outcome ? '-' : \sprintf('%d/%d', $report->passedTrials($caseId), \count($results)),
                mb_strimwidth($detail, 0, 120, '…'),
            ];
        }
        $io->table(['Case', 'Priority', 'Outcome', 'Trials', 'First failure'], $rows);

        $summary = $report->summary();
        $priorities = [];
        foreach (Scalar::keyed($summary['by_priority']) as $priority => $count) {
            $count = Scalar::keyed($count);
            $priorities[] = \sprintf('%s %d/%d', $priority, Scalar::int($count['passed'] ?? null), Scalar::int($count['total'] ?? null));
        }
        $io->definitionList(
            ['Cases' => \sprintf(
                '%d passed, %d failed, %d errors, %d skipped',
                Scalar::int($summary[EvalReport::PASSED]),
                Scalar::int($summary[EvalReport::FAILED]),
                Scalar::int($summary[EvalReport::ERROR]),
                Scalar::int($summary[EvalReport::SKIPPED]),
            )],
            ['By priority' => implode(', ', $priorities) ?: '-'],
            ['Cost' => \sprintf('$%.4f agent + $%.4f judge, $%.4f per turn', Scalar::float($summary['cost_usd']), Scalar::float($summary['judge_cost_usd']), Scalar::float($summary['cost_per_turn_usd']))],
            ['Rounds per turn' => \sprintf('%.2f', Scalar::float($summary['rounds_per_turn']))],
            ['Cache hit rate' => \sprintf('%.1f%%', 100 * Scalar::float($summary['cache_hit_rate']))],
            ['Average turn' => \sprintf('%d ms', Scalar::int($summary['avg_turn_ms']))],
        );
    }

    /** @param array<string, mixed> $meta */
    private function write(string $file, EvalReport $report, array $meta): void
    {
        $results = [];
        foreach ($report->trials as $caseId => $trials) {
            foreach ($trials as $trial => $result) {
                $results[] = [
                    'case' => $caseId,
                    'trial' => $trial,
                    'outcome' => $result->skipped ? EvalReport::SKIPPED : (null !== $result->error ? EvalReport::ERROR : ($result->passed() ? EvalReport::PASSED : EvalReport::FAILED)),
                    'failures' => $result->failures,
                    'judge_failures' => $result->judgeFailures,
                    'error' => $result->error,
                    'recording' => $result->recording?->toArray(),
                ];
            }
        }

        if (!is_dir(\dirname($file)) && !mkdir(\dirname($file), 0o775, true) && !is_dir(\dirname($file))) {
            throw new \RuntimeException(\sprintf('Cannot create %s.', \dirname($file)));
        }
        file_put_contents($file, json_encode([
            'created_at' => date(\DATE_ATOM),
            'meta' => [...$meta, 'min_pass' => $report->minPass],
            'summary' => $report->summary(),
            'results' => $results,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }
}
