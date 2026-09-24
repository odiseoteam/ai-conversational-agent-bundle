<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Controller;

use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Session\SessionResolver;
use Odiseo\AiConversationalAgentBundle\Host\PrincipalResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** The one route that mints a session. Everything after it carries the id and nothing else. */
final class SessionController
{
    public function __construct(
        private readonly SessionResolver $sessions,
        private readonly PrincipalResolver $principal,
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function start(Request $request): JsonResponse
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return ApiError::json('too_many_sessions', 429);
        }

        $record = $this->sessions->start();

        return new JsonResponse([
            'session_id' => $record->sessionId,
            'guest' => $this->principal->isGuest(),
        ], 201);
    }
}
