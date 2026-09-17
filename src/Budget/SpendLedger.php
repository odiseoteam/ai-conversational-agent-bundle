<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Budget;

/**
 * What has been spent, by session and by day. It is a cost control: it stops the bill running
 * away. It is not an abuse control — fifty sessions each under the cap still spend the day's
 * budget, which is what the rate limiter at the edge is for.
 */
interface SpendLedger
{
    public function record(string $sessionId, \DateTimeImmutable $at, float $usd): void;

    public function sessionSpend(string $sessionId): float;

    public function daySpend(\DateTimeImmutable $day): float;
}
