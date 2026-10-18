<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Content;

/**
 * One saved state of an item the host lets people edit: a rule, a document, a storefront's
 * voice. $data is the item as it was saved; null means "back to the default", the configured
 * item, from this version on. A draft has number 0; a published version, its place in the
 * item's history.
 */
final readonly class Version
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public string $kind,
        public string $key,
        public int $number,
        public VersionState $state,
        public ?array $data,
        public ?string $author = null,
        public ?string $note = null,
        public \DateTimeImmutable $at = new \DateTimeImmutable(),
    ) {
    }

    public function isDefault(): bool
    {
        return null === $this->data;
    }
}
