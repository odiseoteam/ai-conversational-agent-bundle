<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Agent;

use Odiseo\AiConversationalAgentBundle\Host\TurnHook;
use Odiseo\AiConversationalAgentBundle\Memory\ExtractMemory;
use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\SessionRecord;
use Odiseo\AiConversationalAgentBundle\Streaming\AgentEvent;
use Odiseo\AiConversationalAgentBundle\Streaming\EventType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * One turn of a session, the same for every surface: the person's message enters the record
 * with whatever the host queued for the model meanwhile, the host's hook runs and the loop
 * streams. A channel adapter (HTTP, console, messaging) only has to render the events and say
 * when the turn's memory is extracted: in place, or once its response is out.
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
    }

    /** What the turn just run leaves to extract; the surface decides when. */
    public function memoryOf(SessionRecord $record, SessionContext $session): ExtractMemory
    {
        return $this->agent->memoryOf($record->messages, $session);
    }

    /** Extracts in place. The reply is already out: a failure is logged, never shown. */
    public function remember(ExtractMemory $turn): void
    {
        try {
            $this->agent->extractMemory($turn);
        } catch (\Throwable $failed) {
            $this->logger->error('memory extraction failed', ['session' => (new SessionContext($turn->sessionId, $turn->principalId))->sessionTag(), 'exception' => $failed]);
        }
    }
}
