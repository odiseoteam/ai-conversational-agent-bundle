<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Presentation;

use Odiseo\AiConversationalAgentBundle\Capability\ToolContext;
use Odiseo\AiConversationalAgentBundle\Streaming\AgentEvent;
use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;

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

    /**
     * The frame a still-streaming call shows so far, or null when the component does not
     * render partially or nothing visible is there yet (no title, every list empty).
     *
     * @param array<string, mixed> $partialInput
     */
    public static function partial(PresentationComponent $component, array $partialInput, ToolContext $tools): ?PartialFrame
    {
        if (null === $component->partial) {
            return null;
        }

        $payload = ($component->partial)($partialInput, new EnrichmentContext($tools));
        if (null === $payload) {
            return null;
        }

        [$hasTitle, $lists] = self::partialSignature($payload);
        if (!$hasTitle && [] !== $lists && !array_filter($lists, static fn (int|array $n): bool => [] !== $n && 0 !== $n)) {
            return null;
        }

        return new PartialFrame($component->component, $payload, json_encode([$hasTitle, $lists], \JSON_THROW_ON_ERROR));
    }

    /**
     * What counts as a visible change while a component streams: a title appearing, and the
     * length of every list on the payload — or, for entries that carry a `products` list, the
     * length of each one.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: bool, 1: array<string, int|list<int>>}
     */
    private static function partialSignature(array $payload): array
    {
        $lists = [];
        foreach ($payload as $key => $value) {
            if (!\is_array($value) || !array_is_list($value)) {
                continue;
            }
            $carryProducts = [] !== $value && !array_filter($value, static fn (mixed $item): bool => !\is_array($item) || !\array_key_exists('products', $item));
            $lists[$key] = $carryProducts
                ? array_map(static fn (array $item): int => \count(\is_array($item['products']) ? $item['products'] : []), $value)
                : \count($value);
        }

        return [!empty($payload['title']), $lists];
    }

    private static function refusal(PresentationRefused $refused): ToolOutcome
    {
        return null === $refused->gate
            ? ToolOutcome::error($refused->getMessage())
            : ToolOutcome::held($refused->gate, $refused->getMessage());
    }
}
