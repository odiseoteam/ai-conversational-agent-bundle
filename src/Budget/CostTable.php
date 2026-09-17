<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;

/**
 * What a call cost. The prices are deployment configuration: the defaults below follow the
 * published list at the time of writing and must be checked against the provider's own price
 * list before a spend cap means anything. An unpriced model costs zero and is logged as such
 * by the policy, rather than silently charging a wrong number.
 */
final class CostTable
{
    /** @var array<string, ModelPrice> */
    private array $prices;

    /** @param array<string, ModelPrice> $prices */
    public function __construct(array $prices = [])
    {
        $this->prices = $prices + self::defaults();
    }

    /** @return array<string, ModelPrice> */
    public static function defaults(): array
    {
        // List prices of the first-party API; a dated id costs the same as its alias.
        return [
            'claude-fable-5-1' => new ModelPrice(10.0, 50.0),
            'claude-fable-5' => new ModelPrice(10.0, 50.0),
            'claude-opus-5' => new ModelPrice(5.0, 25.0),
            'claude-opus-4-8' => new ModelPrice(5.0, 25.0),
            'claude-sonnet-5' => new ModelPrice(2.0, 10.0),
            'claude-sonnet-4-6' => new ModelPrice(3.0, 15.0),
            'claude-haiku-4-5' => new ModelPrice(1.0, 5.0),
            'claude-haiku-4-5-20251001' => new ModelPrice(1.0, 5.0),
        ];
    }

    public function knows(string $model): bool
    {
        return isset($this->prices[$model]);
    }

    public function costOf(string $model, Usage $usage): float
    {
        $price = $this->prices[$model] ?? null;
        if (null === $price) {
            return 0.0;
        }

        return ($usage->inputTokens * $price->inputPerMillion
            + $usage->outputTokens * $price->outputPerMillion
            + $usage->cacheCreationTokens * $price->cacheWrite()
            + $usage->cacheReadTokens * $price->cacheRead()) / 1_000_000;
    }
}
