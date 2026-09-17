<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Provider\Request;

use Odiseo\AiAgentBundle\Capability\ToolSpec;
use Odiseo\AiAgentBundle\Config\ThinkingEffort;

/**
 * One model call, in this product's own vocabulary. An adapter maps it to its provider's
 * wire format; nothing here is shaped by a particular API.
 */
final readonly class TurnRequest
{
    /**
     * @param list<SystemBlock>          $system
     * @param list<array<string, mixed>> $messages a block carrying `cache_hint` is where the rolling breakpoint sits
     * @param list<ToolSpec>             $tools
     */
    public function __construct(
        public string $model,
        public array $system,
        public array $messages,
        public array $tools = [],
        public ToolChoice $toolChoice = new ToolChoice('auto'),
        public int $maxTokens = 2048,
        public ?ThinkingEffort $thinkingEffort = null,
        /** Left null for the turn loop; a judge and a replay pin it to 0 so a run is repeatable. */
        public ?float $temperature = null,
        public float $timeoutSeconds = 120.0,
        /** Ask the provider to cache the tool list as part of the stable prefix. */
        public bool $cacheTools = true,
    ) {
    }
}
