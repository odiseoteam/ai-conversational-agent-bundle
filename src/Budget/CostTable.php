<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Budget;

use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;

/**
 * What a call cost. The defaults follow the published list prices at the time of writing; a
 * deployment adds or corrects a model under `prices`. Every model the agent is configured with
 * must have a price, or the table refuses to be built: an unpriced model would cost zero and no
 * spend cap would ever stop it.
 */
final class CostTable
{
    /** @var array<string, ModelPrice> */
    private array $prices;

    /**
     * @param array<string, ModelPrice|array{input: float|int, output: float|int, cache_write: float|int, cache_read: float|int}> $prices
     * @param list<string>                                                                                                        $models the configured ones, each of which must be priced
     */
    public function __construct(array $prices = [], array $models = [])
    {
        $this->prices = array_map(
            static fn (ModelPrice|array $price): ModelPrice => $price instanceof ModelPrice ? $price : ModelPrice::fromArray($price),
            $prices,
        ) + self::defaults();

        foreach ($models as $model) {
            if ('' !== $model && !$this->knows($model)) {
                throw new \InvalidArgumentException(\sprintf('The model "%s" has no price: add it under prices, with input, output, cache_write and cache_read in USD per million tokens.', $model));
            }
        }
    }

    /** @return array<string, ModelPrice> */
    public static function defaults(): array
    {
        // List prices of the first-party API; a dated id costs the same as its alias. Cache
        // writes are the 5-minute ones (1.25x input) and reads 0.1x.
        $claude = static fn (float $input, float $output): ModelPrice => new ModelPrice($input, $output, $input * 1.25, $input * 0.1);

        return [
            'claude-fable-5-1' => $claude(10.0, 50.0),
            'claude-fable-5' => $claude(10.0, 50.0),
            'claude-opus-5' => $claude(5.0, 25.0),
            'claude-opus-4-8' => $claude(5.0, 25.0),
            'claude-sonnet-5-5' => $claude(2.0, 10.0),
            'claude-sonnet-5' => $claude(2.0, 10.0),
            'claude-sonnet-4-6' => $claude(3.0, 15.0),
            'claude-haiku-5-5' => $claude(0.1, 0.5),
            'claude-haiku-4-5' => $claude(1.0, 5.0),
            'claude-haiku-4-5-20251001' => $claude(1.0, 5.0),
            // OpenAI's standard tier under 272K tokens of context: no cache-write surcharge, and
            // its adapter reports no writes.
            'gpt-5.6-luna' => new ModelPrice(0.2, 1.2, 0.2, 0.02),
            'gpt-5.5' => new ModelPrice(5.0, 30.0, 5.0, 0.5),
            'gpt-5.4' => new ModelPrice(2.5, 15.0, 2.5, 0.25),
            'gpt-5.4-mini' => new ModelPrice(0.75, 4.5, 0.75, 0.075),
            'gpt-5-mini' => new ModelPrice(0.25, 2.0, 0.25, 0.025),
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
            + $usage->cacheCreationTokens * $price->cacheWritePerMillion
            + $usage->cacheReadTokens * $price->cacheReadPerMillion) / 1_000_000;
    }
}
