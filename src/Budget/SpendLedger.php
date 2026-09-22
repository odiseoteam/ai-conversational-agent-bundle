<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;

/**
 * What has been spent, by session, by client and by day. It is a cost control: it stops the
 * bill running away. The client key (an IP, typically) is what survives a fresh session; it
 * is still not an abuse control — that is what the rate limiter at the edge is for.
 */
interface SpendLedger
{
    /** $model and $usage are the detail behind $usd; a ledger may keep them, the caps only sum $usd. */
    public function record(string $sessionId, \DateTimeImmutable $at, float $usd, ?string $clientKey = null, ?string $model = null, ?Usage $usage = null): void;

    public function sessionSpend(string $sessionId): float;

    public function clientSpend(string $clientKey, \DateTimeImmutable $day): float;

    public function daySpend(\DateTimeImmutable $day): float;
}
