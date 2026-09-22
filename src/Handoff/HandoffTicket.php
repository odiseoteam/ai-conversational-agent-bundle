<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

/**
 * What a channel answers when it took a request. $mode says whether the person waits in the
 * conversation (live) or is answered elsewhere (async); $externalRef and $url point at the
 * conversation on the channel's side, when it has one.
 */
final readonly class HandoffTicket
{
    public const ASYNC = 'async';
    public const LIVE = 'live';

    public function __construct(
        public string $reference,
        public string $channel,
        public string $mode = self::ASYNC,
        public ?string $externalRef = null,
        public ?string $url = null,
        /** What happens next, in the person's words; null falls back to the configured line. */
        public ?string $expectation = null,
    ) {
    }
}
