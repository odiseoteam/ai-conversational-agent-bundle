<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Knowledge;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * In-memory BM25 over the sections of every source's documents, ranked together so their scores
 * compare. Words are compared without case or accents, without a final "s" and by their first
 * five letters, so "refunded" finds "refund" and "cafe" finds "café", in any language and without
 * a dictionary. The document's title and the section's headings weigh more. A section comes back
 * whole, or a window around its first match when it is longer than the cap; a hit far below the
 * best one is left out. A source that fails is left out and logged.
 */
final class LexicalSearch implements KnowledgeSearchInterface
{
    private const STEM_CHARS = 5;

    private const MIN_TERM_CHARS = 3;

    private const TITLE_WEIGHT = 3;

    private const K1 = 1.2;

    private const B = 0.75;

    /** @param iterable<KnowledgeSourceInterface> $sources */
    public function __construct(
        private readonly iterable $sources = [],
        private readonly int $maxSectionChars = 3000,
        /** A hit scoring under this share of the best one is left out, so a weak match adds no text. */
        private readonly float $minScoreRatio = 0.5,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $terms = array_values(array_unique($this->terms($query)));
        if ([] === $terms || $limit < 1) {
            return [];
        }

        /** @var list<array{document: KnowledgeDocument, heading: ?string, content: string, tf: array<string, int>, length: int}> $sections */
        $sections = [];
        foreach ($this->documents() as $document) {
            foreach ($this->sections($document->body) as [$heading, $content]) {
                $tf = array_count_values($this->terms($content));
                preg_match_all('/^#{1,6}\s+(.+)$/mu', $content, $headings);
                foreach ($this->terms($document->title.' '.implode(' ', $headings[1])) as $term) {
                    $tf[$term] = ($tf[$term] ?? 0) + self::TITLE_WEIGHT;
                }
                $sections[] = ['document' => $document, 'heading' => $heading, 'content' => $content, 'tf' => $tf, 'length' => array_sum($tf)];
            }
        }
        if ([] === $sections) {
            return [];
        }

        $count = \count($sections);
        $averageLength = max(1, array_sum(array_column($sections, 'length')) / $count);
        $idf = [];
        foreach ($terms as $term) {
            $withTerm = \count(array_filter($sections, static fn (array $s): bool => isset($s['tf'][$term])));
            $idf[$term] = log(1 + ($count - $withTerm + 0.5) / ($withTerm + 0.5));
        }

        $scored = [];
        foreach ($sections as $section) {
            $score = 0.0;
            foreach ($terms as $term) {
                $tf = $section['tf'][$term] ?? 0;
                if ($tf > 0) {
                    $norm = self::K1 * (1 - self::B + self::B * $section['length'] / $averageLength);
                    $score += $idf[$term] * $tf * (self::K1 + 1) / ($tf + $norm);
                }
            }
            if ($score > 0) {
                $scored[] = [$score, $section];
            }
        }
        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $floor = ($scored[0][0] ?? 0) * $this->minScoreRatio;

        $hits = [];
        foreach (\array_slice($scored, 0, $limit) as [$score, $section]) {
            if ($score < $floor) {
                break;
            }
            $hits[] = new KnowledgeHit($section['document'], $this->excerpt($section['content'], $terms), $section['heading']);
        }

        return $hits;
    }

    /** @return iterable<KnowledgeDocument> */
    private function documents(): iterable
    {
        foreach ($this->sources as $source) {
            try {
                $documents = [...$source->documents()];
            } catch (\Throwable $e) {
                $this->logger->warning('Knowledge source {source} left out of the search: {message}', ['source' => $source::class, 'message' => $e->getMessage(), 'exception' => $e]);

                continue;
            }
            yield from $documents;
        }
    }

    /**
     * The text before the first `##` heading, then one section per `##` heading with the deeper
     * headings inside it. A section of headings only is left out.
     *
     * @return list<array{0: ?string, 1: string}>
     */
    private function sections(string $body): array
    {
        $sections = [];
        $heading = null;
        $lines = [];
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            if (1 === preg_match('/^##\s+(.+?)\s*#*$/u', $line, $m)) {
                $sections[] = [$heading, trim(implode("\n", $lines))];
                $heading = $m[1];
                $lines = [];
            }
            $lines[] = $line;
        }
        $sections[] = [$heading, trim(implode("\n", $lines))];

        return array_values(array_filter(
            $sections,
            static fn (array $s): bool => '' !== trim((string) preg_replace('/^#{1,6}\s.*$/mu', '', $s[1])),
        ));
    }

    /** @return list<string> */
    private function terms(string $text): array
    {
        $terms = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $word) {
            $term = $this->stem($word);
            if (mb_strlen($term) >= self::MIN_TERM_CHARS) {
                $terms[] = $term;
            }
        }

        return $terms;
    }

    private function stem(string $word): string
    {
        $decomposed = \Normalizer::normalize($word, \Normalizer::FORM_D);
        $folded = (string) preg_replace('/\p{Mn}+/u', '', \is_string($decomposed) ? $decomposed : $word);
        if (mb_strlen($folded) > 3 && str_ends_with($folded, 's')) {
            $folded = substr($folded, 0, -1);
        }

        return mb_substr($folded, 0, self::STEM_CHARS);
    }

    /** @param list<string> $terms */
    private function excerpt(string $content, array $terms): string
    {
        $length = mb_strlen($content);
        if ($length <= $this->maxSectionChars) {
            return $content;
        }

        $start = 0;
        preg_match_all('/[\p{L}\p{N}]+/u', $content, $words, \PREG_OFFSET_CAPTURE);
        foreach ($words[0] as [$word, $offset]) {
            if (\in_array($this->stem(mb_strtolower($word)), $terms, true)) {
                $start = max(0, mb_strlen(substr($content, 0, $offset)) - 200);

                break;
            }
        }
        $start = min($start, $length - $this->maxSectionChars);

        return ($start > 0 ? '…' : '').mb_substr($content, $start, $this->maxSectionChars).($start + $this->maxSectionChars < $length ? '…' : '');
    }
}
