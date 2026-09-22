<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

/**
 * The handoff records' home. Kept apart from the session: a request outlives the session it
 * came from, and whoever answers it reads this, not the transcript store.
 */
interface HandoffStore
{
    public function save(HandoffRecord $record): void;

    public function find(string $reference): ?HandoffRecord;

    /**
     * Newest first.
     *
     * @return list<HandoffRecord>
     */
    public function list(?string $status = null, int $limit = 50, int $offset = 0): array;

    public function count(?string $status = null): int;

    public function setStatus(string $reference, string $status): void;
}
