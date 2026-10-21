<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Command;

use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmMemoryStore;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmSessionStore;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmSpendLedger;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryRetentionInterface;
use Odiseo\AiConversationalAgentBundle\Memory\NullMemoryRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies the retention: conversations with their messages and spend past
 * `conversations.retention_days`, memory facts past the retention the host sets at runtime or,
 * without one, `memory.retention_days`. Null keeps them.
 * Meant for a daily cron.
 */
#[AsCommand(name: 'agent:prune', description: 'Drop the conversations and memory past their retention')]
final class PruneCommand extends Command
{
    public function __construct(
        private readonly OrmSessionStore $sessions,
        private readonly OrmSpendLedger $ledger,
        private readonly OrmMemoryStore $memory,
        private readonly ?int $conversationRetentionDays,
        private readonly ?int $memoryRetentionDays,
        private readonly MemoryRetentionInterface $memoryRetention = new NullMemoryRetention(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be dropped, drop nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $conversations = $spend = $facts = null;
        $memoryDays = $this->memoryRetention->retentionDays($this->memoryRetentionDays);
        if (null !== $this->conversationRetentionDays) {
            // The spend has no key to the conversation, only its session id.
            $ids = $this->sessions->prune(self::cutoff($this->conversationRetentionDays), $dryRun);
            $conversations = \count($ids);
            $spend = $this->ledger->forget($ids, $dryRun);
        }
        if (null !== $memoryDays) {
            $facts = $this->memory->prune(self::cutoff($memoryDays), $dryRun);
        }

        $io->table(['', $dryRun ? 'Would drop' : 'Dropped', 'Retention'], [
            ['Conversations', $conversations ?? '-', self::days($this->conversationRetentionDays)],
            ['Spend entries', $spend ?? '-', self::days($this->conversationRetentionDays)],
            ['Memory facts', $facts ?? '-', self::days($memoryDays)],
        ]);

        return Command::SUCCESS;
    }

    private static function cutoff(int $days): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('-%d days', $days));
    }

    private static function days(?int $days): string
    {
        return null === $days ? 'kept' : $days.' days';
    }
}
