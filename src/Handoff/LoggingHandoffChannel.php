<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** The default: the request goes to the log and nowhere else. A deployment replaces it. */
final class LoggingHandoffChannel implements HandoffChannel
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    public function name(): string
    {
        return 'log';
    }

    public function open(HandoffRequest $request): HandoffTicket
    {
        $this->logger->notice('handoff requested', ['reference' => $request->reference, 'reason' => $request->reason->value, 'summary' => $request->summary]);

        return new HandoffTicket($request->reference, $this->name());
    }
}
