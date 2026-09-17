<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Memory;

/**
 * Where facts live, keyed by subject (the principal). A deployment puts its own store behind
 * this; the runtime above it does not change.
 */
interface MemoryStore
{
    public function save(string $subject, MemoryFact $fact): void;

    /** @return list<MemoryFact> newest first */
    public function all(string $subject): array;

    /** @return list<MemoryFact> the facts matching $query, newest first */
    public function search(string $subject, string $query, int $limit = 10): array;

    public function forget(string $subject, string $key): bool;

    public function clear(string $subject): void;
}
