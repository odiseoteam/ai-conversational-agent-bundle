<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Knowledge;

/**
 * One Markdown file per document, with optional front matter: `id`, `title`, `kind` (policy,
 * guide or snippet), `when`, `url` and `enabled`. Without it the file name is the id and the
 * first heading the title. A missing or empty directory is no document, not an error. Files are
 * read on every search, so an edit shows up at once.
 */
final class MarkdownKnowledgeSource implements KnowledgeSourceInterface
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function documents(): iterable
    {
        $files = is_dir($this->directory) ? glob(rtrim($this->directory, '/').'/*.md') : false;
        foreach (false === $files ? [] : $files as $file) {
            $raw = file_get_contents($file);
            if (false === $raw) {
                continue;
            }
            [$meta, $body] = $this->split($raw);
            if (\in_array(strtolower($meta['enabled'] ?? ''), ['false', 'no', '0'], true)) {
                continue;
            }
            $slug = basename($file, '.md');

            yield new KnowledgeDocument(
                id: $meta['id'] ?? $slug,
                title: $meta['title'] ?? $this->firstHeading($body) ?? $slug,
                body: trim($body),
                kind: KnowledgeKind::tryFrom(strtolower($meta['kind'] ?? '')) ?? KnowledgeKind::Policy,
                when: $meta['when'] ?? '',
                url: $meta['url'] ?? null,
            );
        }
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function split(string $raw): array
    {
        if (1 !== preg_match('/\A---\s*\n(.*?)\n---\s*\n?(.*)\z/su', $raw, $m)) {
            return [[], $raw];
        }

        $meta = [];
        foreach (preg_split('/\R/u', $m[1]) ?: [] as $line) {
            if (1 === preg_match('/^([A-Za-z_][\w-]*)\s*:\s*(.*)$/u', $line, $kv)) {
                $meta[strtolower($kv[1])] = trim($kv[2], " \t\"'");
            }
        }

        return [$meta, $m[2]];
    }

    private function firstHeading(string $body): ?string
    {
        return 1 === preg_match('/^#{1,6}\s+(.+)$/mu', $body, $m) ? trim($m[1]) : null;
    }
}
