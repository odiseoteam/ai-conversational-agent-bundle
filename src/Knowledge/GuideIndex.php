<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Knowledge;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The guides of every source, for the index in the cached prompt: by title, then id, so the
 * text only changes when a guide does. A guide that repeats an id, has a "when" over the cap or
 * comes after the count cap is left out and logged; a source that fails too.
 */
final class GuideIndex
{
    /** @var list<KnowledgeDocument>|null */
    private ?array $guides = null;

    /** @param iterable<KnowledgeSourceInterface> $sources */
    public function __construct(
        private readonly iterable $sources = [],
        private readonly int $maxGuides = 20,
        private readonly int $maxWhenChars = 200,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @return list<KnowledgeDocument> */
    public function guides(): array
    {
        return $this->guides ??= $this->collect();
    }

    /** @return list<KnowledgeDocument> */
    private function collect(): array
    {
        /** @var array<string, KnowledgeDocument> $guides */
        $guides = [];
        foreach ($this->sources as $source) {
            try {
                $documents = [...$source->documents()];
            } catch (\Throwable $e) {
                $this->logger->warning('Knowledge source {source} left out of the guide index: {message}', ['source' => $source::class, 'message' => $e->getMessage(), 'exception' => $e]);

                continue;
            }
            foreach ($documents as $document) {
                if (KnowledgeKind::Guide !== $document->kind) {
                    continue;
                }
                if (isset($guides[$document->id])) {
                    $this->skip($document, 'its id is already used');
                } elseif (mb_strlen($document->when) > $this->maxWhenChars) {
                    $this->skip($document, \sprintf('its "when" is longer than %d characters', $this->maxWhenChars));
                } else {
                    $guides[$document->id] = $document;
                }
            }
        }

        $guides = array_values($guides);
        usort($guides, static fn (KnowledgeDocument $a, KnowledgeDocument $b): int => [$a->title, $a->id] <=> [$b->title, $b->id]);
        foreach (\array_slice($guides, $this->maxGuides) as $guide) {
            $this->skip($guide, \sprintf('only %d guides are listed', $this->maxGuides));
        }

        return \array_slice($guides, 0, $this->maxGuides);
    }

    private function skip(KnowledgeDocument $guide, string $reason): void
    {
        $this->logger->warning('Guide "{id}" left out of the index: {reason}.', ['id' => $guide->id, 'reason' => $reason]);
    }
}
