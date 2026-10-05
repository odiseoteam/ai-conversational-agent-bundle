<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony\EventListener;

use Odiseo\AiConversationalAgentBundle\Agent\TurnRunner;
use Odiseo\AiConversationalAgentBundle\Memory\ExtractMemory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A streamed turn leaves its extraction here and it goes out once the response has been sent,
 * so the person is not kept waiting on it. With a bus the message is dispatched and its routing
 * decides where it runs; without one it runs here.
 */
final class MemoryExtractionListener
{
    /** @var list<ExtractMemory> */
    private array $pending = [];

    public function __construct(
        private readonly TurnRunner $turns,
        private readonly LoggerInterface $logger,
        private readonly ?MessageBusInterface $bus = null,
    ) {
    }

    public function defer(ExtractMemory $turn): void
    {
        $this->pending[] = $turn;
    }

    public function onTerminate(TerminateEvent $event): void
    {
        // Taken first: the service outlives the request on a long-running runtime.
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $turn) {
            if (null === $this->bus) {
                $this->turns->remember($turn);

                continue;
            }

            try {
                $this->bus->dispatch($turn);
            } catch (\Throwable $failed) {
                // The response is out; nobody is left to tell.
                $this->logger->error('memory extraction failed', ['exception' => $failed]);
            }
        }
    }
}
