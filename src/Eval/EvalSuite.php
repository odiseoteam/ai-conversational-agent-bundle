<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

/** The cases under one directory: one JSON file per flow, an array of cases in each. */
final class EvalSuite
{
    /** @param list<EvalCase> $cases */
    public function __construct(public readonly array $cases)
    {
    }

    public static function fromDirectory(string $root): self
    {
        $files = glob(rtrim($root, '/').'/*.json') ?: [];
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!\is_array($decoded)) {
                throw new \RuntimeException(\sprintf('%s: not a JSON array of cases', $file));
            }

            foreach ($decoded as $row) {
                if (\is_array($row)) {
                    $cases[] = EvalCase::fromArray($row);
                }
            }
        }

        return new self($cases);
    }

    /** @param list<string> $tags */
    public function filter(?string $priority = null, array $tags = []): self
    {
        return new self(array_values(array_filter($this->cases, static function (EvalCase $case) use ($priority, $tags): bool {
            if (null !== $priority && $case->priority !== $priority) {
                return false;
            }

            return [] === $tags || [] !== array_intersect($tags, $case->tags);
        })));
    }
}
