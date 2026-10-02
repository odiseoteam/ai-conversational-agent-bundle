<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider;

/**
 * What a provider can do with one model. The loop reads it and degrades explicitly: without
 * forced tool choice it prefetches the grounding read, without prompt caching it sends no
 * markers, without thinking it asks for none, without tool-input deltas it waits for each call
 * whole, and without temperature it sends none.
 *
 * An adapter knows its models; a deployment completes that for a model the adapter does not
 * know yet, under `capabilities`, with the same names in snake case.
 */
final readonly class ProviderCapabilities
{
    private const NAMES = [
        'streaming' => 'streaming',
        'prompt_caching' => 'promptCaching',
        'server_tools' => 'serverTools',
        'forced_tool_choice' => 'forcedToolChoice',
        'thinking' => 'thinking',
        'usage_accounting' => 'usageAccounting',
        'parallel_tool_calls' => 'parallelToolCalls',
        'tool_input_deltas' => 'toolInputDeltas',
        'temperature' => 'temperature',
    ];

    public function __construct(
        public bool $streaming = true,
        public bool $promptCaching = false,
        public bool $serverTools = false,
        public bool $forcedToolChoice = false,
        public bool $thinking = false,
        public bool $usageAccounting = true,
        public bool $parallelToolCalls = false,
        public bool $toolInputDeltas = false,
        public bool $temperature = true,
    ) {
    }

    /** @param array<string, bool> $overrides by snake-case name */
    public function with(array $overrides): self
    {
        /** @var array<string, bool> $values */
        $values = get_object_vars($this);
        foreach ($overrides as $name => $value) {
            $property = self::NAMES[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown capability "%s"; known: %s.', $name, implode(', ', array_keys(self::NAMES))));
            $values[$property] = $value;
        }

        return new self(...$values);
    }
}
