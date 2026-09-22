<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

/** What a deployment decides about handing over. */
final readonly class HandoffSettings
{
    public function __construct(
        /** Off, the capability registers nothing: no tool, no prompt line, no component. */
        public bool $enabled = true,
        /** A visitor with no account has to leave a way to be reached before a request opens. */
        public bool $contactRequiredForGuests = true,
        /** What the person is told happens next, when the channel says nothing more specific. */
        public string $expectation = 'The team reads it and gets back to you.',
        /** How many recent messages travel with the request. */
        public int $excerptMessages = 12,
    ) {
    }
}
