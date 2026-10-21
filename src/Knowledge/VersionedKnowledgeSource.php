<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Knowledge;

use Odiseo\AiConversationalAgentBundle\Content\VersionStoreInterface;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;

/**
 * The file documents with what was published at runtime laid over them, the same way as the
 * rules: a version replaces the document with its id, one back to the default leaves the file,
 * a disabled one leaves it out, and a document that only exists as versions comes after.
 */
final readonly class VersionedKnowledgeSource implements KnowledgeSourceInterface
{
    public const KIND = 'knowledge';

    public function __construct(
        private VersionStoreInterface $versions,
        private ?KnowledgeSourceInterface $files = null,
    ) {
    }

    public function documents(): iterable
    {
        $published = $this->versions->published(self::KIND);
        $seen = [];
        foreach ($this->files?->documents() ?? [] as $document) {
            $seen[$document->id] = true;
            $data = ($published[$document->id] ?? null)?->data;
            if (null === $data) {
                yield $document;
            } elseif (self::enabled($data)) {
                yield self::fromData($document->id, $data);
            }
        }
        foreach ($published as $id => $version) {
            if (!isset($seen[$id]) && null !== $version->data && self::enabled($version->data)) {
                yield self::fromData($id, $version->data);
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function enabled(array $data): bool
    {
        return false !== ($data['enabled'] ?? true);
    }

    /** @param array<string, mixed> $data */
    public static function fromData(string $id, array $data): KnowledgeDocument
    {
        return new KnowledgeDocument(
            id: $id,
            title: Scalar::string($data['title'] ?? null, $id),
            body: trim(Scalar::string($data['body'] ?? null)),
            kind: KnowledgeKind::tryFrom(Scalar::string($data['kind'] ?? null)) ?? KnowledgeKind::Policy,
            when: Scalar::string($data['when'] ?? null),
            url: Scalar::nullableString($data['url'] ?? null) ?: null,
        );
    }

    /** @return array{title: string, kind: string, when: string, body: string, url: ?string, enabled: bool} */
    public static function toData(KnowledgeDocument $document, bool $enabled = true): array
    {
        return [
            'title' => $document->title,
            'kind' => $document->kind->value,
            'when' => $document->when,
            'body' => $document->body,
            'url' => $document->url,
            'enabled' => $enabled,
        ];
    }
}
