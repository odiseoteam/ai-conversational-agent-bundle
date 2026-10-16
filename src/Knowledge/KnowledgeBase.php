<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Knowledge;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs every search and merges their hits by position (reciprocal rank fusion): the scores of
 * two searches are not comparable, their ranks are. With one search, the usual case, its order is
 * kept. A search that fails is left out and logged, so a turn never fails over it.
 */
final class KnowledgeBase
{
    private const RANK_CONSTANT = 60;

    /** @param iterable<KnowledgeSearchInterface> $searches */
    public function __construct(
        private readonly iterable $searches = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @return list<KnowledgeHit> */
    public function search(string $query, int $limit): array
    {
        /** @var array<string, float> $scores */
        $scores = [];
        /** @var array<string, KnowledgeHit> $hits */
        $hits = [];
        foreach ($this->searches as $search) {
            try {
                $found = $search->search($query, $limit);
            } catch (\Throwable $e) {
                $this->logger->warning('Knowledge search {search} left out: {message}', ['search' => $search::class, 'message' => $e->getMessage(), 'exception' => $e]);

                continue;
            }
            foreach (array_values($found) as $rank => $hit) {
                $key = $hit->document->id."\n".($hit->section ?? '');
                $scores[$key] = ($scores[$key] ?? 0.0) + 1 / (self::RANK_CONSTANT + $rank + 1);
                $hits[$key] ??= $hit;
            }
        }
        arsort($scores);

        $merged = [];
        foreach (\array_slice(array_keys($scores), 0, max(0, $limit)) as $key) {
            $merged[] = $hits[$key];
        }

        return $merged;
    }
}
