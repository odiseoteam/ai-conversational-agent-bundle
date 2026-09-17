<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Presentation;

use Odiseo\AiConversationalAgentBundle\Gate\ProvenanceGate;
use Odiseo\AiConversationalAgentBundle\Session\Provenance;
use Odiseo\AiConversationalAgentBundle\Session\SeenRecord;

/**
 * What a validator and an enrich hook do to the model's arguments before they become a
 * component: bound every string, keep lists to their caps, and let a record onto the card only
 * when the session saw it. A vertical adds what its records look like on a card.
 */
final class PayloadGuard
{
    public static function text(mixed $value, int $maxChars, string $field): string
    {
        $text = \is_scalar($value) ? trim((string) $value) : '';
        if ('' === $text) {
            throw new \InvalidArgumentException(\sprintf('%s is required.', $field));
        }

        return mb_substr($text, 0, $maxChars);
    }

    public static function optionalText(mixed $value, int $maxChars): ?string
    {
        $text = \is_scalar($value) ? trim((string) $value) : '';

        return '' === $text ? null : mb_substr($text, 0, $maxChars);
    }

    /** @return list<string> */
    public static function textList(mixed $value, int $max, int $maxChars): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $items = array_values(array_filter(array_map(
            static fn (mixed $v): ?string => self::optionalText($v, $maxChars),
            $value,
        )));

        return \array_slice($items, 0, $max);
    }

    /** @return list<array<string, mixed>> */
    public static function rows(mixed $value, string $field, int $max, int $min = 1): array
    {
        $rows = \is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
        if (\count($rows) < $min) {
            throw new \InvalidArgumentException(\sprintf('%s needs at least %d entr%s.', $field, $min, 1 === $min ? 'y' : 'ies'));
        }

        return \array_slice($rows, 0, $max);
    }

    /**
     * The record behind an id the model wrote, or null with a note for the model when the
     * session never saw it (or saw something of another kind under that id).
     */
    public static function seen(EnrichmentContext $context, mixed $rawId, string $kind, string $resolveHint): ?SeenRecord
    {
        $id = \is_scalar($rawId) ? trim((string) $rawId) : '';
        $record = '' === $id ? null : Provenance::find($context->tools->state, $id, $kind);
        if (null === $record) {
            $context->note(\sprintf('%s was left off the card: %s', $id, ProvenanceGate::message($id, $resolveHint)));

            return null;
        }

        return $record;
    }

    /**
     * @param list<array<string, mixed>> $enriched
     *
     * @return list<array<string, mixed>>
     *
     * @throws PresentationRefused when nothing survived provenance: an empty card is never rendered
     */
    public static function requireAny(array $enriched, string $what): array
    {
        if ([] === $enriched) {
            throw new PresentationRefused(\sprintf('None of the ids on this %s were returned by a tool in this session, so nothing can be shown. Look them up first, then call it again.', $what), ProvenanceGate::NAME);
        }

        return $enriched;
    }
}
