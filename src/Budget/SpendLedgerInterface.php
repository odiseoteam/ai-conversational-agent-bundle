<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;

/**
 * What has been spent, by session, by client and by day. It is a cost control: it stops the
 * bill running away. The client key (an IP, typically) is what survives a fresh session; it
 * is still not an abuse control — that is what the rate limiter at the edge is for.
 */
interface SpendLedgerInterface
{
    /**
     * $model and $usage are the detail behind $usd; a ledger may keep them, the caps only sum $usd.
     * $scope is the part of the deployment the call belongs to, for that part's own day cap.
     */
    public function record(string $sessionId, \DateTimeImmutable $at, float $usd, ?string $clientKey = null, ?string $model = null, ?Usage $usage = null, ?string $scope = null): void;

    public function sessionSpend(string $sessionId): float;

    public function clientSpend(string $clientKey, \DateTimeImmutable $day): float;

    public function daySpend(\DateTimeImmutable $day): float;

    public function scopeSpend(string $scope, \DateTimeImmutable $day): float;

    /** The spend of $month's calendar month, from its first day to its last. */
    public function monthSpend(\DateTimeImmutable $month): float;

    public function scopeMonthSpend(string $scope, \DateTimeImmutable $month): float;
}
