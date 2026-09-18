<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Presentation;

use Odiseo\AiConversationalAgentBundle\Capability\Capability;
use Odiseo\AiConversationalAgentBundle\Capability\Limits;
use Odiseo\AiConversationalAgentBundle\Capability\ToolContext;
use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Execution\ChipComponent;
use Odiseo\AiConversationalAgentBundle\Fencing\Sanitizer;
use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;

/**
 * The turn's chips. Every vertical wants them and they carry no domain of their own, so they
 * are the one component the core ships.
 *
 * They are sanitized here rather than in the tool schema, because the schemas are cache-frozen;
 * a call whose every chip sanitizes away fails, since the chips are its whole content.
 */
final class SuggestionsCapability implements Capability
{
    public function __construct(private readonly Limits $limits)
    {
    }

    public function name(): string
    {
        return 'core.suggestions';
    }

    public function tools(): array
    {
        return [new ToolSpec(
            ChipComponent::TOOL,
            \sprintf(
                'Give the turn its 1-%d chips; it ends the reply. Call it in the same round as the turn\'s last component, without waiting for that component\'s result. Alone, after the text, only on a turn with no component (a terms answer, a clarifying question, a confirmed add or save).',
                $this->limits->maxChipsPerTurn,
            ),
            [
                'type' => 'object',
                'properties' => [
                    'suggestions' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => $this->limits->maxChipsPerTurn,
                        'items' => ['type' => 'string', 'maxLength' => Sanitizer::SUGGESTION_CHIP_MAX_CHARS],
                        'description' => 'Short imperatives, each a different kind of step, none of them something this turn already showed.',
                    ],
                ],
                'required' => ['suggestions'],
                'additionalProperties' => false,
            ],
            wantsStatusLine: false,
        )];
    }

    public function promptFragments(): array
    {
        return [];
    }

    public function groundingRules(): array
    {
        return [];
    }

    public function components(): array
    {
        $max = $this->limits->maxChipsPerTurn;

        return [new PresentationComponent(
            ChipComponent::TOOL,
            ChipComponent::COMPONENT,
            static function (array $input) use ($max): array {
                $raw = \is_array($input['suggestions'] ?? null) ? $input['suggestions'] : [];
                $chips = Sanitizer::suggestionChips($raw, $max);

                if ([] === $chips) {
                    throw new \InvalidArgumentException('every suggestion was empty after sanitization — send 1 to '.$max.' short, plain-text suggestions.');
                }

                return ['suggestions' => $chips];
            },
        )];
    }

    public function execute(string $tool, array $input, ToolContext $context): ToolOutcome
    {
        // Presentation tools are run by the executor, never through here.
        return ToolOutcome::error(\sprintf('Unknown tool: %s', $tool));
    }
}
