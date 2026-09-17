<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Session;

/**
 * One record a tool returned this session. It is what the provenance gate checks against and
 * what presentation enrichment joins on, so it holds the id, what kind of thing it is, and
 * the fields the vertical needs to render it again without another read.
 */
final readonly class SeenRecord
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $id,
        public string $kind,
        public array $data = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'data' => $this->data];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['kind'] ?? ''),
            \is_array($row['data'] ?? null) ? $row['data'] : [],
        );
    }
}
