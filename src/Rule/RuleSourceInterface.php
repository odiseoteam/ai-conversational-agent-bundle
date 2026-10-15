<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Rule;

/**
 * Where rules come from. Every tagged source adds its rules; an id must be unique across them.
 * A source is read once per process, so its rules must not change between turns.
 */
interface RuleSourceInterface
{
    /** @return iterable<Rule> */
    public function rules(): iterable;
}
