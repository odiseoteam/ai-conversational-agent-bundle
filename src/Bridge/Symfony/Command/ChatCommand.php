<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Command;

use Odiseo\AiConversationalAgentBundle\Agent\TurnRunner;
use Odiseo\AiConversationalAgentBundle\Host\ConsoleEnvironment;
use Odiseo\AiConversationalAgentBundle\Provider\AuthenticationException;
use Odiseo\AiConversationalAgentBundle\Session\SessionResolver;
use Odiseo\AiConversationalAgentBundle\Session\SessionStore;
use Odiseo\AiConversationalAgentBundle\Streaming\AgentEvent;
use Odiseo\AiConversationalAgentBundle\Streaming\EventType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A text console over the same turn runner the chat route uses, to try the agent without a
 * browser. Every `ui` event is printed as component and payload: what a frontend renders, read
 * as data. The host prepares whatever its tools need to work outside a request.
 */
#[AsCommand(name: 'agent:chat', description: 'Chat with the agent from the terminal')]
final class ChatCommand extends Command
{
    public function __construct(
        private readonly TurnRunner $turns,
        private readonly SessionStore $sessions,
        private readonly SessionResolver $resolver,
        private readonly ConsoleEnvironment $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('principal', null, InputOption::VALUE_REQUIRED, 'Subject the memory is filed under', 'console-dev');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->environment->prepare();

        $record = $this->sessions->start((string) $input->getOption('principal'));
        $session = $this->resolver->context($record);
        $io->writeln(\sprintf('Session %s. Ctrl-D to quit.', $record->sessionId));

        while (true) {
            $output->write("\n> ");
            $line = fgets(\STDIN);
            if (false === $line) {
                break;
            }
            $message = trim($line);
            if ('' === $message) {
                continue;
            }

            try {
                foreach ($this->turns->run($record, $session, $message) as $event) {
                    $this->render($output, $event);
                }
            } catch (AuthenticationException $failed) {
                $io->error('The model credential was rejected: '.$failed->getMessage());

                return Command::FAILURE;
            } catch (\Throwable $failed) {
                $io->error('Turn failed: '.$failed->getMessage());
                continue;
            }

            $this->sessions->save($record);
        }

        return Command::SUCCESS;
    }

    private function render(OutputInterface $output, AgentEvent $event): void
    {
        $data = $event->data;
        switch ($event->type) {
            case EventType::TextDelta:
                $output->write($data['text']);
                break;
            case EventType::ToolCall:
                $output->writeln(\sprintf("\n  · %s%s", $data['tool'], isset($data['label']) ? ' — '.$data['label'] : ''));
                break;
            case EventType::ToolResult:
                if ('ok' !== $data['status']) {
                    $output->writeln(\sprintf('  ! %s: %s', $data['tool'], $data['reason'] ?? 'error'));
                }
                break;
            case EventType::Ui:
                $output->writeln(\sprintf("\n[%s]\n%s", $data['component'], json_encode($data['payload'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
                break;
            case EventType::UiPartial:
                break;
            case EventType::Progress:
                $output->writeln("\n  … ".$data['message']);
                break;
            case EventType::StateUpdate:
                $output->writeln(\sprintf("\n[state:%s] %s", $data['key'], json_encode($data['value'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
                break;
            case EventType::Error:
                $output->writeln("\n[error] ".$data['message']);
                break;
            case EventType::TurnComplete:
                $output->writeln(\sprintf("\n(%d ms · %s)", $data['elapsed_ms'], json_encode($data['usage'])));
                break;
        }
    }
}
