<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider;

/** The credential was missing or rejected, which is a deployment problem, not a turn problem. */
final class AuthenticationException extends ProviderException
{
}
