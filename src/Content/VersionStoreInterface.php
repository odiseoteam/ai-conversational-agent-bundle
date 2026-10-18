<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Content;

/**
 * The versions of what people edit at runtime, by kind (rule, knowledge…) and key (the item's
 * id). Publishing adds a version and never changes an old one; an item has one draft at most.
 */
interface VersionStoreInterface extends RevisionInterface
{
    /**
     * The newest published version of each item of $kind, by key, in key order.
     *
     * @return array<string, Version>
     */
    public function published(string $kind): array;

    /**
     * The published versions of one item, newest first.
     *
     * @return list<Version>
     */
    public function history(string $kind, string $key): array;

    public function draft(string $kind, string $key): ?Version;

    /**
     * The items of $kind with a draft, by key.
     *
     * @return array<string, Version>
     */
    public function drafts(string $kind): array;

    /**
     * Saves $data as the item's draft, replacing the one it had.
     *
     * @param array<string, mixed> $data
     */
    public function saveDraft(string $kind, string $key, array $data, ?string $author = null): Version;

    public function discardDraft(string $kind, string $key): void;

    /**
     * Publishes $data as the item's next version and drops its draft; null goes back to the
     * default. An item with a default (a configured one) counts that as version 1, so its first
     * saved version is 2.
     *
     * @param array<string, mixed>|null $data
     */
    public function publish(string $kind, string $key, ?array $data, ?string $author = null, ?string $note = null, bool $hasDefault = false): Version;
}
