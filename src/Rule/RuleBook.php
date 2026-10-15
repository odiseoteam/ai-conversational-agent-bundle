<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Rule;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The enabled rules of every source, in a stable order: by category, then as each source gives
 * them. They render into the cached prompt, so the caps keep it short. The configuration is
 * checked when the container is built; a rule from another source that repeats an id or breaks
 * a cap is left out and logged, so a turn never fails over it.
 */
final class RuleBook
{
    /** @var list<Rule>|null */
    private ?array $rules = null;

    /** @param iterable<RuleSourceInterface> $sources */
    public function __construct(
        private readonly iterable $sources = [],
        private readonly int $maxRules = 20,
        private readonly int $maxRuleChars = 400,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->rules ??= $this->collect();
    }

    /** @return list<Rule> */
    private function collect(): array
    {
        $seen = [];
        /** @var array<string, list<Rule>> $byCategory */
        $byCategory = [];
        foreach (RuleCategory::cases() as $category) {
            $byCategory[$category->value] = [];
        }

        foreach ($this->sources as $source) {
            foreach ($source->rules() as $rule) {
                if (isset($seen[$rule->id])) {
                    $this->skip($rule, 'its id is already used');

                    continue;
                }
                $seen[$rule->id] = true;

                if (!$rule->enabled) {
                    continue;
                }
                if (mb_strlen($rule->text) > $this->maxRuleChars) {
                    $this->skip($rule, \sprintf('it is longer than %d characters', $this->maxRuleChars));

                    continue;
                }
                $byCategory[$rule->category->value][] = $rule;
            }
        }

        $rules = array_merge(...array_values($byCategory));
        foreach (\array_slice($rules, $this->maxRules) as $rule) {
            $this->skip($rule, \sprintf('only %d rules are used', $this->maxRules));
        }

        return \array_slice($rules, 0, $this->maxRules);
    }

    private function skip(Rule $rule, string $reason): void
    {
        $this->logger->warning('Rule "{id}" left out of the prompt: {reason}.', ['id' => $rule->id, 'reason' => $reason]);
    }
}
