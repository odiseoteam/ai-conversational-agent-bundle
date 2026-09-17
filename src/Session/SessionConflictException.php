<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Session;

/** Another request wrote this session first; the caller retries from a fresh load. */
final class SessionConflictException extends \RuntimeException
{
}
