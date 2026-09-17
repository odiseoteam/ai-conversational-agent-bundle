<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Agent;

use Odiseo\AiAgentBundle\Host\TurnHook;
use Odiseo\AiAgentBundle\Session\SessionContext;
use Odiseo\AiAgentBundle\Session\SessionRecord;
use Odiseo\AiAgentBundle\Streaming\AgentEvent;
use Odiseo\AiAgentBundle\Streaming\EventType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * One turn of a session, the same for every surface: the person's message enters the record
 * with whatever the host queued for the model meanwhile, the host's hook runs, the loop
 * streams, and memory is extracted once the reply is out. A channel adapter (HTTP, console,
 * messaging) only has to render the events.
 */
final class TurnRunner
{
    public function __construct(
        private readonly AgentLoop $agent,
        private readonly TurnHook $hook,
        private readonly string $appEventsLabel,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @return \Generator<int, AgentEvent> */
    public function run(SessionRecord $record, SessionContext $session, string $message): \Generator
    {
        $this->hook->beforeTurn($session);

        $record->messages[] = Transcript::userTurn($message, $record->pendingAppEvents, $this->appEventsLabel);
        $record->pendingAppEvents = [];

        foreach ($this->agent->streamTurn($record->messages, $session, $record->state) as $event) {
            if (EventType::TurnComplete === $event->type && 0 !== ($event->data['results_cleared'] ?? 0)) {
                // Earlier messages changed: the stored transcript is rewritten whole.
                $record->storedMessages = 0;
            }
            yield $event;
        }

        // The reply is already out: a failure here is logged, never shown.
        try {
            $this->agent->updateMemory($record->messages, $session);
        } catch (\Throwable $failed) {
            $this->logger->error('memory extraction failed', ['session' => $session->sessionTag(), 'exception' => $failed]);
        }
    }
}
