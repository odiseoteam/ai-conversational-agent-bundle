<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Config;

/** No runtime changes: the deployment's settings as configured. */
final class NullConfigOverrides implements ConfigOverridesInterface
{
    public function changes(): array
    {
        return [];
    }
}
