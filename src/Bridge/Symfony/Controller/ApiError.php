<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Controller;

use Odiseo\AiConversationalAgentBundle\Streaming\AgentEvent;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * What the agent's routes answer when they refuse: a code the host translates and a neutral
 * English message as the fallback. Same pair on a JSON response and on the SSE error event.
 */
final class ApiError
{
    public const MESSAGES = [
        'too_many_sessions' => 'Too many sessions from this connection. Please try later.',
        'too_many_messages' => 'Too many messages in a short time. Please try again shortly.',
        'empty_message' => 'The message is empty.',
        'not_configured' => 'The assistant is not configured in this environment.',
        'turn_failed' => 'Something went wrong on our side. Please try again in a moment.',
    ];

    public static function json(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => self::MESSAGES[$code]], $status);
    }

    public static function event(string $code): AgentEvent
    {
        return AgentEvent::error($code, self::MESSAGES[$code]);
    }
}
