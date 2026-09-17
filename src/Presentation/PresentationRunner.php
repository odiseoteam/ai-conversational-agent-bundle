<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Presentation;

use Odiseo\AiAgentBundle\Capability\ToolContext;
use Odiseo\AiAgentBundle\Streaming\AgentEvent;
use Odiseo\AiAgentBundle\Streaming\ToolOutcome;

final class PresentationRunner
{
    /**
     * Validate, enrich and emit one component. The result text is $displayedText plus the
     * hook's notes; the ui event carries the enriched payload.
     *
     * @param array<string, mixed> $input
     */
    public static function run(
        PresentationComponent $component,
        array $input,
        ToolContext $tools,
        string $displayedText,
    ): ToolOutcome {
        $context = new EnrichmentContext($tools);

        try {
            $payload = ($component->validate)($input);
        } catch (PresentationRefused $refused) {
            return self::refusal($refused);
        } catch (\InvalidArgumentException|\ValueError $invalid) {
            return ToolOutcome::error(\sprintf('Invalid %s payload: %s', $component->tool, $invalid->getMessage()));
        }

        if (null !== $component->enrich) {
            try {
                $payload = ($component->enrich)($payload, $context);
            } catch (PresentationRefused $refused) {
                return self::refusal($refused);
            } catch (\InvalidArgumentException|\ValueError $invalid) {
                return ToolOutcome::error($invalid->getMessage());
            }
        }

        $text = implode(' ', [$displayedText, ...$context->notes()]);

        return new ToolOutcome($text, [AgentEvent::ui($component->component, $payload)]);
    }

    private static function refusal(PresentationRefused $refused): ToolOutcome
    {
        return null === $refused->gate
            ? ToolOutcome::error($refused->getMessage())
            : ToolOutcome::held($refused->gate, $refused->getMessage());
    }
}
