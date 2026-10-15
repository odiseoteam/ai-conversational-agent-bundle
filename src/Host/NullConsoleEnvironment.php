<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Host;

final class NullConsoleEnvironment implements ConsoleEnvironmentInterface
{
    public function prepare(): void
    {
    }
}
