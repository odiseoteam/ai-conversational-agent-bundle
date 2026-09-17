<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider;

/** The credential was missing or rejected, which is a deployment problem, not a turn problem. */
final class AuthenticationException extends ProviderException
{
}
