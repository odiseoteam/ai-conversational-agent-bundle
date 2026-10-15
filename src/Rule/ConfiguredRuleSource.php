<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Rule;

/** The rules written in the bundle's `rules` configuration, in the order they are written. */
final readonly class ConfiguredRuleSource implements RuleSourceInterface
{
    /** @param array<string, array{title: string, category: string, text: string, enabled: bool}> $rules keyed by id */
    public function __construct(private array $rules)
    {
    }

    public function rules(): iterable
    {
        foreach ($this->rules as $id => $rule) {
            yield new Rule((string) $id, $rule['title'], RuleCategory::from($rule['category']), $rule['text'], $rule['enabled']);
        }
    }
}
