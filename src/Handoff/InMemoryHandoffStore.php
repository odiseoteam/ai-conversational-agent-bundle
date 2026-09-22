<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

final class InMemoryHandoffStore implements HandoffStore
{
    /** @var array<string, HandoffRecord> */
    private array $records = [];

    public function save(HandoffRecord $record): void
    {
        $this->records[$record->reference] = $record;
    }

    public function find(string $reference): ?HandoffRecord
    {
        return $this->records[$reference] ?? null;
    }

    /** @return list<HandoffRecord> */
    public function list(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $all = array_values(array_filter($this->records, static fn (HandoffRecord $r): bool => null === $status || $r->status === $status));
        usort($all, static fn (HandoffRecord $a, HandoffRecord $b): int => $b->createdAt <=> $a->createdAt);

        return \array_slice($all, $offset, $limit);
    }

    public function count(?string $status = null): int
    {
        return \count($this->list($status, \PHP_INT_MAX));
    }

    public function setStatus(string $reference, string $status): void
    {
        if (isset($this->records[$reference])) {
            $this->records[$reference]->status = $status;
            $this->records[$reference]->updatedAt = new \DateTimeImmutable();
        }
    }
}
