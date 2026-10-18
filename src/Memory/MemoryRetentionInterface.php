<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Memory;

/**
 * The memory retention a host sets at runtime (one per storefront, say) over the configured one.
 * A fact belongs to a person, not to a storefront, so the host answers one retention for them all.
 */
interface MemoryRetentionInterface
{
    /**
     * @param int|null $configured `memory.retention_days`
     *
     * @return int|null days to keep a fact, null to keep them all
     */
    public function retentionDays(?int $configured): ?int;
}
