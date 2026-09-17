<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Execution;

use Odiseo\AiAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiAgentBundle\Capability\ToolSpec;

/**
 * The tool list one deployment sends, built once. These are prompt bytes: the order and the
 * schemas must be identical on every request, or the cached prefix is re-read on every call.
 */
final class ToolSurface
{
    public const STATUS_FIELD = 'status';
    public const STATUS_MAX_CHARS = 60;

    /** @var list<ToolSpec>|null */
    private ?array $tools = null;

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly ExecutorWording $wording = new ExecutorWording(),
    ) {
    }

    /** @return list<ToolSpec> */
    public function tools(): array
    {
        return $this->tools ??= array_map(
            fn (ToolSpec $tool): ToolSpec => $tool->wantsStatusLine ? $this->withStatus($tool) : $tool,
            $this->capabilities->tools(),
        );
    }

    /**
     * Every tool but the presentation tools and the provider's own takes an optional status
     * line first: a few words the person waiting sees while the call runs. It is the model's
     * text, so it is sanitized like any display string, and it never reaches a handler.
     */
    private function withStatus(ToolSpec $tool): ToolSpec
    {
        $schema = $tool->inputSchema;
        $schema['properties'] = [
            self::STATUS_FIELD => [
                'type' => 'string',
                'maxLength' => self::STATUS_MAX_CHARS,
                'description' => \sprintf(
                    'A few plain words %s sees while this runs, saying what you are doing for them; no tool or system names.',
                    $this->wording->statusReader,
                ),
            ],
            ...($schema['properties'] ?? []),
        ];

        return new ToolSpec($tool->name, $tool->description, $schema, $tool->wantsStatusLine, $tool->providerDefinition);
    }
}
