<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Session;

/**
 * The session state the host carries between turns: the provenance record, and whatever the
 * vertical needs to keep. A dropped id needs a fresh read, which is the point of the cap.
 */
final class TurnState
{
    private const PROVENANCE_CAP = 200;

    /** @var array<string, SeenRecord> newest last */
    private array $seenRecords = [];

    /** @var array<string, mixed> */
    private array $vertical = [];

    public function remember(SeenRecord ...$records): void
    {
        foreach ($records as $record) {
            if ('' === $record->id) {
                continue;
            }
            unset($this->seenRecords[$record->id]);
            $this->seenRecords[$record->id] = $record;
        }

        while (\count($this->seenRecords) > self::PROVENANCE_CAP) {
            array_shift($this->seenRecords);
        }
    }

    public function hasSeen(string $id): bool
    {
        return isset($this->seenRecords[$id]);
    }

    public function seen(string $id): ?SeenRecord
    {
        return $this->seenRecords[$id] ?? null;
    }

    /** @return list<string> */
    public function seenIds(): array
    {
        return array_keys($this->seenRecords);
    }

    /** @return array<string, SeenRecord> */
    public function seenRecords(): array
    {
        return $this->seenRecords;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->vertical[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->vertical[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'seen_records' => array_map(static fn (SeenRecord $r): array => $r->toArray(), array_values($this->seenRecords)),
            'vertical' => $this->vertical,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $state = new self();
        foreach (\is_array($row['seen_records'] ?? null) ? $row['seen_records'] : [] as $record) {
            if (\is_array($record)) {
                $state->remember(SeenRecord::fromArray($record));
            }
        }
        $state->vertical = \is_array($row['vertical'] ?? null) ? $row['vertical'] : [];

        return $state;
    }
}
