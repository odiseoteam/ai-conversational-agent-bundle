<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Session;

use Odiseo\AiConversationalAgentBundle\Host\PrincipalResolver;
use Odiseo\AiConversationalAgentBundle\Session\SessionConflictException;
use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\SessionRecord;
use Odiseo\AiConversationalAgentBundle\Session\SessionStore;
use Odiseo\AiConversationalAgentBundle\Session\UnknownSessionException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * The agent session a request belongs to, held until the response is out so the write-back
 * listener stores it once; routes never call save themselves.
 *
 * After the start route a request carries the session id and nothing else. The principal lives
 * in the record and is checked against the host's on every request, so a session cannot be
 * picked up by somebody else and a visitor who signs in starts afresh.
 */
final class SessionResolver
{
    public const HEADER = 'X-Session-Id';

    private ?SessionRecord $record = null;

    public function __construct(
        private readonly SessionStore $sessions,
        private readonly PrincipalResolver $principal,
        private readonly ?string $timezone = null,
    ) {
    }

    public function start(): SessionRecord
    {
        return $this->record = $this->sessions->start($this->principal->principalId());
    }

    public function resolve(Request $request): SessionRecord
    {
        $sessionId = (string) $request->headers->get(self::HEADER, '');
        if ('' === $sessionId) {
            throw new UnauthorizedHttpException('Session', 'Start a session first.');
        }

        try {
            $record = $this->sessions->require($sessionId);
        } catch (UnknownSessionException $unknown) {
            throw new UnauthorizedHttpException('Session', 'Unknown session.', $unknown);
        }

        if ($record->principalId !== $this->principal->principalId()) {
            throw new UnauthorizedHttpException('Session', 'The session belongs to another principal; start a new one.');
        }

        return $this->record = $record;
    }

    /** @param array<string, mixed> $surface what the host knows about where the person is (page, channel) */
    public function context(SessionRecord $record, array $surface = []): SessionContext
    {
        return new SessionContext(
            sessionId: $record->sessionId,
            principalId: $record->principalId,
            timezone: $this->timezone ?? date_default_timezone_get(),
            surface: $surface,
            guest: $this->principal->isGuest(),
        );
    }

    public function save(): void
    {
        if (null === $this->record) {
            return;
        }

        try {
            $this->sessions->save($this->record);
        } catch (SessionConflictException $conflict) {
            throw new ConflictHttpException('The session changed; retry.', $conflict);
        } finally {
            $this->record = null;
        }
    }
}
