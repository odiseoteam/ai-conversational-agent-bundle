<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Rule;

/**
 * Where rules come from. Every tagged source adds its rules; an id must be unique across them.
 * A source is read again only when the revision changes (something was published), so its
 * rules must not change between turns otherwise.
 */
interface RuleSourceInterface
{
    /** @return iterable<Rule> */
    public function rules(): iterable;
}
