<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Budget;

use Odiseo\AiConversationalAgentBundle\Budget\CostTable;
use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;
use PHPUnit\Framework\TestCase;

final class CostTableTest extends TestCase
{
    public function testAConfiguredModelWithoutAPriceIsRefused(): void
    {
        $this->expectExceptionMessage('The model "gpt-5.4-mini" has no price');

        new CostTable([], ['claude-sonnet-5', 'gpt-5.4-mini']);
    }

    public function testAConfiguredPriceIsUsedWithItsOwnCacheRates(): void
    {
        $costs = new CostTable(['gpt-5.4-mini' => ['input' => 0.25, 'output' => 2.0, 'cache_write' => 0.25, 'cache_read' => 0.025]], ['gpt-5.4-mini']);

        self::assertEqualsWithDelta(0.25 + 2.0 + 0.25 + 0.025, $costs->costOf('gpt-5.4-mini', new Usage(1_000_000, 1_000_000, 1_000_000, 1_000_000)), 1e-9);
    }

    public function testAConfiguredPriceReplacesTheDefault(): void
    {
        $costs = new CostTable(['claude-sonnet-5' => ['input' => 1.0, 'output' => 1.0, 'cache_write' => 1.0, 'cache_read' => 1.0]]);

        self::assertEqualsWithDelta(1.0, $costs->costOf('claude-sonnet-5', new Usage(1_000_000)), 1e-9);
    }
}
