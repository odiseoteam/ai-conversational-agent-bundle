<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Execution;

use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Capability\Limits;
use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Fencing\Sanitizer;
use Odiseo\AiConversationalAgentBundle\Presentation\ChipComponent;
use Odiseo\AiConversationalAgentBundle\Presentation\ChipMode;
use Odiseo\AiConversationalAgentBundle\Presentation\PresentationComponent;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
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

    /** @var list<ToolSpec>|null */
    private ?array $withReply = null;

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
                true === ($components[$tool->name] ?? null)?->chips->inField() => $this->withChips($tool, $components[$tool->name]),
                default => $tool,
            },
            $this->capabilities->tools(),
        );
    }

    /**
     * The list for one model. A model that hides text written before a tool call gets the
     * reply as the chips tool's first argument, so the turn's answer is written where it shows.
     *
     * @return list<ToolSpec>
     */
    public function toolsFor(ProviderCapabilities $can): array
    {
        if ($can->textBeforeToolCalls) {
            return $this->tools();
        }

        return $this->withReply ??= array_map(
            fn (ToolSpec $tool): ToolSpec => ChipComponent::TOOL === $tool->name ? $this->withReply($tool) : $tool,
            $this->tools(),
        );
    }

    private function withReply(ToolSpec $tool): ToolSpec
    {
        $schema = $tool->inputSchema;
        $schema['properties'] = [
            ChipComponent::REPLY_FIELD => [
                'type' => 'string',
                'description' => 'The turn\'s whole reply when the turn has no component, shown before the chips. Text written outside this call is not shown.',
            ],
            ...Scalar::keyed($schema['properties'] ?? null),
        ];

        return new ToolSpec(
            $tool->name,
            $tool->description.' On a turn with no component, write the reply in `'.ChipComponent::REPLY_FIELD.'`: text written before this call is not shown.',
            $schema,
            $tool->wantsStatusLine,
            streamsInput: true,
        );
    }

    /**
     * A component that takes the turn's chips gets the field last, so the model writes them
     * once the component itself is written. The component's chip note follows the tool's
     * description, where the model weighs it more than inside the field.
     */
    private function withChips(ToolSpec $tool, PresentationComponent $component): ToolSpec
    {
        $required = ChipMode::RequiredField === $component->chips;
        $schema = $tool->inputSchema;
        $schema['properties'] = [
            ...Scalar::keyed($schema['properties'] ?? null),
            ChipComponent::FIELD => [
                'type' => 'array',
                ...($required ? ['minItems' => 1] : []),
                'maxItems' => $this->limits->maxChipsPerTurn,
                'items' => ['type' => 'string', 'maxLength' => Sanitizer::SUGGESTION_CHIP_MAX_CHARS],
                'description' => \sprintf(
                    $required
                        ? 'The turn\'s chips: 1-%d short imperatives, each a different kind of step, none of them something this turn already showed. When another component follows in the turn, the last one\'s are shown.'
                        : 'The turn\'s chips, when this is its last component: 1-%d short imperatives, each a different kind of step, none of them something this turn already showed.',
                    $this->limits->maxChipsPerTurn,
                ),
            ],
        ];
        if ($required) {
            $schema['required'] = [...Scalar::strings($schema['required'] ?? null), ChipComponent::FIELD];
        }

        $description = '' === $component->chipNote ? $tool->description : $tool->description.' '.$component->chipNote;

        return new ToolSpec($tool->name, $description, $schema, $tool->wantsStatusLine, $tool->streamsInput);
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

        return new ToolSpec($tool->name, $tool->description, $schema, $tool->wantsStatusLine, $tool->streamsInput);
    }
}
