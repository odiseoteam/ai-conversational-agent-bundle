<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Memory;

/** Only the configured retention. */
final class NullMemoryRetention implements MemoryRetentionInterface
{
    public function retentionDays(?int $configured): ?int
    {
        return $configured;
    }
}
