<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Bridge\Symfony\EventListener;

use Odiseo\AiAgentBundle\Session\SessionResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * The record is written once the response has been sent — after a streamed turn has ended — so
 * a turn and a host action both land, and a route that only read writes nothing.
 */
final readonly class SessionWriteBackListener
{
    public function __construct(
        private SessionResolver $sessions,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: TerminateEvent::class)]
    public function onTerminate(TerminateEvent $event): void
    {
        try {
            $this->sessions->save();
        } catch (\Throwable $failed) {
            // The response is out; nobody is left to tell.
            $this->logger->error('the agent session could not be written back', ['exception' => $failed]);
        }
    }
}
