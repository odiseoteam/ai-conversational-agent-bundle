<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture;

use Odiseo\AiConversationalAgentBundle\Capability\ScopeArea;
use Odiseo\AiConversationalAgentBundle\Capability\ScopedCapabilityInterface;
use Odiseo\AiConversationalAgentBundle\Capability\ToolContext;
use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;

/** A capability with no tools that only names an area of what the assistant does. */
final class AreaCapability implements ScopedCapabilityInterface
{
    public function __construct(
        private readonly string $area,
        private readonly int $priority = 0,
    ) {
    }

    public function name(): string
    {
        return 'test.area.'.$this->area;
    }

    public function tools(): array
    {
        return [];
    }

    public function promptFragments(): array
    {
        return [];
    }

    public function groundingRules(): array
    {
        return [];
    }

    public function components(): array
    {
        return [];
    }

    public function scopeArea(): ScopeArea
    {
        return new ScopeArea($this->area, $this->priority);
    }

    public function execute(string $tool, array $input, ToolContext $context): ToolOutcome
    {
        throw new \LogicException('No tools.');
    }
}
