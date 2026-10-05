<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Memory;

/**
 * What a finished turn leaves to be remembered. Plain values only, so a host can extract in
 * place or hand it to whatever queue it runs.
 */
final readonly class ExtractMemory
{
    public function __construct(
        public string $principalId,
        public string $sessionId,
        public string $exchange,
    ) {
    }
}
