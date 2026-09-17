<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Host;

final class NullConsoleEnvironment implements ConsoleEnvironment
{
    public function prepare(): void
    {
    }
}
