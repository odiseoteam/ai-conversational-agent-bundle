<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Capability;

use Odiseo\AiAgentBundle\Grounding\GroundingRule;
use Odiseo\AiAgentBundle\Presentation\PresentationComponent;
use Odiseo\AiAgentBundle\Streaming\ToolOutcome;

/**
 * A unit of what an agent can do, declared by a vertical and assembled by the core.
 *
 * A capability is a stateless service: everything about the caller and the turn arrives in
 * the ToolContext. It contributes tools, the prompt rules that belong to those tools,
 * grounding rules, presentation components, and the handlers behind them.
 */
interface Capability
{
    /** Stable identifier, e.g. "content.search". Used for ordering and for logs. */
    public function name(): string;

    /** @return list<ToolSpec> in a fixed order */
    public function tools(): array;

    /** @return list<PromptFragment> */
    public function promptFragments(): array;

    /** @return list<GroundingRule> in precedence order within this capability */
    public function groundingRules(): array;

    /** @return list<PresentationComponent> */
    public function components(): array;

    /**
     * Runs one of this capability's tools. Throwing is allowed: the executor's failure ladder
     * turns an unmapped exception into "temporarily unavailable" and logs it.
     *
     * @param array<string, mixed> $input the call's arguments, without the status line
     */
    public function execute(string $tool, array $input, ToolContext $context): ToolOutcome;
}
