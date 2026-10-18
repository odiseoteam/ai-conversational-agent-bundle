<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Rule;

use Odiseo\AiConversationalAgentBundle\Content\VersionStoreInterface;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;

/**
 * The configured rules with what was published at runtime laid over them: a published version
 * replaces the rule with its id, one back to the default leaves the configured rule, and a rule
 * that only exists as versions comes after the configured ones, in id order.
 */
final readonly class VersionedRuleSource implements RuleSourceInterface
{
    public const KIND = 'rule';

    public function __construct(
        private RuleSourceInterface $configured,
        private VersionStoreInterface $versions,
    ) {
    }

    public function rules(): iterable
    {
        $published = $this->versions->published(self::KIND);
        $seen = [];
        foreach ($this->configured->rules() as $rule) {
            $seen[$rule->id] = true;
            $data = ($published[$rule->id] ?? null)?->data;
            yield null === $data ? $rule : self::fromData($rule->id, $data);
        }
        foreach ($published as $id => $version) {
            if (!isset($seen[$id]) && null !== $version->data) {
                yield self::fromData($id, $version->data);
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromData(string $id, array $data): Rule
    {
        return new Rule(
            $id,
            Scalar::string($data['title'] ?? null, $id),
            RuleCategory::tryFrom(Scalar::string($data['category'] ?? null)) ?? RuleCategory::Other,
            Scalar::string($data['text'] ?? null),
            false !== ($data['enabled'] ?? true),
        );
    }

    /** @return array{title: string, category: string, text: string, enabled: bool} */
    public static function toData(Rule $rule): array
    {
        return ['title' => $rule->title, 'category' => $rule->category->value, 'text' => $rule->text, 'enabled' => $rule->enabled];
    }
}
