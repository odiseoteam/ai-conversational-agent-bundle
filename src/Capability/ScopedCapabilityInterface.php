<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Capability;

/**
 * A capability that adds an area to what the assistant does. The prompt lists the areas of the
 * registered capabilities, so registering one widens what the assistant stays within.
 */
interface ScopedCapabilityInterface extends CapabilityInterface
{
    public function scopeArea(): ScopeArea;
}
