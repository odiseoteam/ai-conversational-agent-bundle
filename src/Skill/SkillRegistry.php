<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Skill;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A skill is a directory holding SKILL.md: YAML frontmatter with `name` and `description`,
 * then the instructions. The static prompt carries the index; `load_skill` returns a body on
 * demand. Skills are sorted by name so the index renders the same bytes every time.
 */
final class SkillRegistry
{
    /** @var list<Skill> */
    private array $skills;

    /** @var array<string, Skill> */
    private array $byName = [];

    /** @param iterable<Skill> $skills */
    public function __construct(iterable $skills = [])
    {
        $sorted = \is_array($skills) ? array_values($skills) : iterator_to_array($skills, false);
        usort($sorted, static fn (Skill $a, Skill $b): int => $a->name <=> $b->name);

        $this->skills = $sorted;
        foreach ($sorted as $skill) {
            $this->byName[$skill->name] = $skill;
        }
    }

    /**
     * Every skill directory directly under $root; names must be unique. Nested directories are
     * not read, so a flow parked under `_staged/` stays out of the index until it is moved up.
     */
    public static function fromDirectory(string $root): self
    {
        if (!is_dir($root)) {
            throw new SkillLoadError(\sprintf('%s: not a directory', $root));
        }

        $names = scandir($root) ?: [];
        sort($names);

        $skills = [];
        foreach ($names as $name) {
            if (str_starts_with($name, '.')) {
                continue;
            }
            $dir = $root.'/'.$name;
            if (is_dir($dir) && is_file($dir.'/SKILL.md')) {
                $skills[] = self::loadDirectory($dir);
            }
        }

        $seen = [];
        foreach ($skills as $skill) {
            if (isset($seen[$skill->name])) {
                throw new SkillLoadError(\sprintf('duplicate skill name: %s', $skill->name));
            }
            $seen[$skill->name] = true;
        }

        return new self($skills);
    }

    public static function loadDirectory(string $dir): Skill
    {
        $path = $dir.'/SKILL.md';
        if (!is_file($path)) {
            throw new SkillLoadError(\sprintf('%s: no SKILL.md found', $dir));
        }

        return self::parse((string) file_get_contents($path), $path);
    }

    public static function parse(string $text, ?string $path = null): Skill
    {
        $where = $path ?? 'SKILL.md';
        if (!str_starts_with($text, '---')) {
            throw new SkillLoadError(\sprintf('%s: missing YAML frontmatter', $where));
        }

        $parts = explode('---', $text, 3);
        if (3 !== \count($parts)) {
            throw new SkillLoadError(\sprintf('%s: malformed frontmatter fences', $where));
        }

        try {
            $meta = Yaml::parse($parts[1]) ?? [];
        } catch (ParseException $malformed) {
            throw new SkillLoadError(\sprintf('%s: the frontmatter is not valid YAML (%s). A description containing a colon has to be quoted.', $where, $malformed->getMessage()), 0, $malformed);
        }
        $name = \is_array($meta) ? ($meta['name'] ?? null) : null;
        $description = \is_array($meta) ? ($meta['description'] ?? null) : null;
        if (!\is_string($name) || '' === $name || !\is_string($description) || '' === trim($description)) {
            throw new SkillLoadError(\sprintf('%s: frontmatter needs `name` and `description`', $where));
        }

        return new Skill($name, trim($description), trim($parts[2]));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    public function indexBlock(): string
    {
        if ([] === $this->skills) {
            return '(no skills installed)';
        }

        return implode("\n", array_map(
            static fn (Skill $skill): string => \sprintf('- `%s` — %s', $skill->name, $skill->description),
            $this->skills,
        ));
    }

    public function instructions(string $name): ?string
    {
        return $this->byName[$name]->body ?? null;
    }
}
