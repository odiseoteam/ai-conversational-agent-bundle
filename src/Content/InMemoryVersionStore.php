<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Content;

/** Versions kept for the process only: without a database nothing is edited at runtime. */
final class InMemoryVersionStore implements VersionStoreInterface
{
    /** @var array<string, array<string, list<Version>>> by kind and key, oldest first */
    private array $published = [];

    /** @var array<string, array<string, Version>> */
    private array $drafts = [];

    private int $revision = 0;

    public function revision(): string
    {
        return (string) $this->revision;
    }

    public function published(string $kind): array
    {
        $newest = [];
        foreach ($this->published[$kind] ?? [] as $key => $versions) {
            $newest[$key] = $versions[\count($versions) - 1];
        }
        ksort($newest, \SORT_STRING);

        return $newest;
    }

    public function history(string $kind, string $key): array
    {
        return array_reverse($this->published[$kind][$key] ?? []);
    }

    public function draft(string $kind, string $key): ?Version
    {
        return $this->drafts[$kind][$key] ?? null;
    }

    public function drafts(string $kind): array
    {
        $drafts = $this->drafts[$kind] ?? [];
        ksort($drafts, \SORT_STRING);

        return $drafts;
    }

    public function saveDraft(string $kind, string $key, array $data, ?string $author = null): Version
    {
        return $this->drafts[$kind][$key] = new Version($kind, $key, 0, VersionState::Draft, $data, $author);
    }

    public function discardDraft(string $kind, string $key): void
    {
        unset($this->drafts[$kind][$key]);
    }

    public function publish(string $kind, string $key, ?array $data, ?string $author = null, ?string $note = null, bool $hasDefault = false): Version
    {
        $last = $this->history($kind, $key)[0] ?? null;
        $number = max(null === $last ? 0 : $last->number, $hasDefault ? 1 : 0) + 1;
        $version = new Version($kind, $key, $number, VersionState::Published, $data, $author, $note);

        $this->published[$kind][$key][] = $version;
        unset($this->drafts[$kind][$key]);
        ++$this->revision;

        return $version;
    }
}
