<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Grounding;

use Odiseo\AiConversationalAgentBundle\Session\TurnState;

final class GroundingResolver
{
    /**
     * The first rule that fires, by precedence. A provider that can force a tool pins the
     * round to it; one that cannot prefetches the read and injects it with the rule's intro.
     *
     * @param iterable<GroundingRule> $rules
     */
    public static function resolve(iterable $rules, string $text, TurnState $state): ?ForcedRead
    {
        foreach ($rules as $rule) {
            $input = $rule->fires($text, $state);
            if (null !== $input) {
                return new ForcedRead($rule, $input);
            }
        }

        return null;
    }
}
