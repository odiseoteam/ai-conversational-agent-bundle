<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Memory;

/**
 * One stored fact. The shape is bounded here; what a value may be about is the write filter's
 * decision.
 *
 * A recalled fact carries the session that wrote it, so the model reads it as a past turn's
 * claim and a poisoned session's writes stay traceable. The value is the session tag, never
 * the session id, which on a host with no authentication in front of it is also the credential.
 */
final readonly class MemoryFact
{
    public function __construct(
        public string $key,
        public string $value,
        public MemoryCategory $category = MemoryCategory::Preference,
        public ?\DateTimeImmutable $updatedAt = null,
        public ?string $sourceSessionTag = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'category' => $this->category->value,
            'updated_at' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
            'source_session' => $this->sourceSessionTag,
        ];
    }

    /**
     * What the prompt and a recall result carry: enough to use, enough to attribute.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return array_filter([
            'key' => $this->key,
            'value' => $this->value,
            'category' => $this->category->value,
            'saved_in_session' => $this->sourceSessionTag,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $updatedAt = isset($row['updated_at']) && \is_string($row['updated_at'])
            ? new \DateTimeImmutable($row['updated_at'])
            : null;

        return new self(
            (string) ($row['key'] ?? ''),
            (string) ($row['value'] ?? ''),
            MemoryCategory::tryFrom((string) ($row['category'] ?? '')) ?? MemoryCategory::Preference,
            $updatedAt,
            isset($row['source_session']) ? (string) $row['source_session'] : null,
        );
    }
}
