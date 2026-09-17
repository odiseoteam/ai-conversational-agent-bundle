<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Memory;

final class InMemoryMemoryStore implements MemoryStore
{
    /** @var array<string, array<string, MemoryFact>> */
    private array $facts = [];

    public function save(string $subject, MemoryFact $fact): void
    {
        unset($this->facts[$subject][$fact->key]);
        $this->facts[$subject][$fact->key] = $fact;
    }

    public function all(string $subject): array
    {
        return array_reverse(array_values($this->facts[$subject] ?? []));
    }

    public function search(string $subject, string $query, int $limit = 10): array
    {
        $needle = mb_strtolower(trim($query));
        if ('' === $needle) {
            return \array_slice($this->all($subject), 0, $limit);
        }

        $words = array_filter(preg_split('/\s+/u', $needle) ?: []);
        $matches = [];
        foreach ($this->all($subject) as $fact) {
            $haystack = mb_strtolower($fact->key.' '.$fact->value);
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    $matches[] = $fact;
                    break;
                }
            }
        }

        return \array_slice($matches, 0, $limit);
    }

    public function forget(string $subject, string $key): bool
    {
        if (!isset($this->facts[$subject][$key])) {
            return false;
        }

        unset($this->facts[$subject][$key]);

        return true;
    }

    public function clear(string $subject): void
    {
        unset($this->facts[$subject]);
    }
}
