<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Execution;

use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Capability\Limits;
use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Fencing\Sanitizer;
use Odiseo\AiConversationalAgentBundle\Presentation\ChipComponent;
use Odiseo\AiConversationalAgentBundle\Presentation\ChipMode;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;

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
        private readonly Limits $limits = new Limits(),
    ) {
    }

    /** @return list<ToolSpec> */
    public function tools(): array
    {
        if (null !== $this->tools) {
            return $this->tools;
        }

        $components = $this->capabilities->components();

        return $this->tools = array_map(
            fn (ToolSpec $tool): ToolSpec => match (true) {
                $tool->wantsStatusLine => $this->withStatus($tool),
                ChipMode::Field === ($components[$tool->name] ?? null)?->chips => $this->withChips($tool),
                default => $tool,
            },
            $this->capabilities->tools(),
        );
    }

    /**
     * A component that takes the turn's chips gets the field last, so the model writes them
     * once the component itself is written.
     */
    private function withChips(ToolSpec $tool): ToolSpec
    {
        $schema = $tool->inputSchema;
        $schema['properties'] = [
            ...Scalar::keyed($schema['properties'] ?? null),
            ChipComponent::FIELD => [
                'type' => 'array',
                'maxItems' => $this->limits->maxChipsPerTurn,
                'items' => ['type' => 'string', 'maxLength' => Sanitizer::SUGGESTION_CHIP_MAX_CHARS],
                'description' => \sprintf(
                    'The turn\'s chips, when this is its last component: 1-%d short imperatives, each a different kind of step, none of them something this turn already showed.',
                    $this->limits->maxChipsPerTurn,
                ),
            ],
        ];

        return new ToolSpec($tool->name, $tool->description, $schema, $tool->wantsStatusLine);
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
            ...Scalar::keyed($schema['properties'] ?? null),
        ];

        return new ToolSpec($tool->name, $tool->description, $schema, $tool->wantsStatusLine);
    }
}
