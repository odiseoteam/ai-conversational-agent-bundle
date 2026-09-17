<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Host;

/**
 * What the host needs in place for its tools to work from the console, where no HTTP request
 * exists. A store pushes a request with an in-memory session so its cart has somewhere to live.
 */
interface ConsoleEnvironment
{
    public function prepare(): void;
}
