<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Config;

/**
 * What the host changes at runtime, without a deploy: settings an administrator saved, for
 * example. The keys are AgentConfig's constructor arguments; a key left out keeps the
 * deployment's value, and an unknown one is ignored.
 *
 * Called on every read, so an implementation keeps its own lookups cheap.
 */
interface ConfigOverridesInterface
{
    /** @return array<string, mixed> */
    public function changes(): array;
}
