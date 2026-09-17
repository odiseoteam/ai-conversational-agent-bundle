<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Session;

/**
 * The caller and the moment, as every capability receives them.
 *
 * The principal is bound once, at session start, by code that authenticated the caller. No
 * route and no tool argument carries it, so nothing the model writes can change who it acts
 * for. The clock is the caller's: with no timezone the prompt carries no local time, because
 * the server's clock is not theirs.
 */
final readonly class SessionContext
{
    /** @param array<string, mixed> $surface where the caller is (a page, a channel) */
    public function __construct(
        public string $sessionId,
        public string $principalId,
        public ?string $timezone = null,
        public ?\DateTimeImmutable $now = null,
        public array $surface = [],
        public bool $guest = true,
    ) {
    }

    public function localNow(): ?\DateTimeImmutable
    {
        if (null !== $this->now) {
            return $this->now;
        }

        if (null === $this->timezone) {
            return null;
        }

        return new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
    }

    /**
     * What a log line may carry. The session id is also the request credential on a host with
     * no authentication in front of it, so it never reaches a log whole.
     */
    public function sessionTag(): string
    {
        return substr(hash('sha256', $this->sessionId), 0, 12);
    }
}
