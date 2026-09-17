<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Prompt;

use Odiseo\AiConversationalAgentBundle\Provider\Request\SystemBlock;

/**
 * Where the cache breakpoints go: after the static system text, after the last tool, and
 * rolling through the conversation on the newest persisted message; and where the per-request
 * context goes: a second system block, behind the static one's marker.
 *
 * The request's stable prefix is the tool list and the static system text. Everything per
 * request — the caller's profile, the page, the clock, the memory facts — is the second
 * block, and it is the same bytes from one turn to the next until the state in it changes, so
 * a turn whose state has not moved reads the whole conversation from cache.
 *
 * This class owns where the blocks and markers sit. What goes in them belongs to the prompt
 * builders and the verticals.
 */
final class PromptAssembler
{
    /**
     * The session clock as the context block carries it: the current hour, with the session's
     * offset. Rendering the minutes would change the block, and so re-read the conversation,
     * on nearly every turn.
     */
    public static function contextClock(\DateTimeImmutable $now): string
    {
        return $now->setTime((int) $now->format('G'), 0)->format('Y-m-d\TH:iP');
    }

    /**
     * The system prompt: the static text carrying the cache breakpoint, then the per-request
     * context behind it. Nothing per request goes in the first block; a byte's change there
     * would re-read the tool list and the static text on every call.
     *
     * @return list<SystemBlock>
     */
    public static function systemBlocks(string $staticText, string $context): array
    {
        return [
            new SystemBlock($staticText, cacheHint: true),
            new SystemBlock($context),
        ];
    }

    /**
     * The outgoing request's messages: a request-shaped copy with the rolling cache breakpoint
     * on the newest persisted content block.
     *
     * Within a turn the system blocks are constant, so the marker makes each round's prior
     * rounds — the long tool results especially — a cache read instead of a reprocess. Two
     * cases skip it. A bare first call would write an entry a one-shot session never reads.
     * And the caller passes $rollingBreakpoint = false on a round whose tool choice is not
     * automatic, because the tool choice keys the cached span: an entry written under a forced
     * round is unreadable by the automatic rounds that follow.
     *
     * A turn that closed on a presentation round leaves a tool-result message followed by the
     * next user message; the two go out as one user message, tool results first. The returned
     * list copies the touched message and blocks, strips a marker an earlier call placed, and
     * never mutates the host's stored history.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    public static function requestMessages(array $messages, bool $rollingBreakpoint = true): array
    {
        if ([] === $messages) {
            return [];
        }

        $request = [];
        foreach ($messages as $message) {
            $message = self::withoutMarker($message);
            $last = [] === $request ? null : $request[\count($request) - 1];

            if (null !== $last && 'user' === ($message['role'] ?? null) && 'user' === ($last['role'] ?? null)) {
                $request[\count($request) - 1]['content'] = [
                    ...self::blocks($last['content'] ?? null),
                    ...self::blocks($message['content'] ?? null),
                ];
                continue;
            }

            $request[] = $message;
        }

        if (!$rollingBreakpoint || \count($request) < 2) {
            return $request;
        }

        $content = self::blocks($request[\count($request) - 1]['content'] ?? null);
        if ([] !== $content && \is_array($content[\count($content) - 1])) {
            $content[\count($content) - 1]['cache_hint'] = true;
            $request[\count($request) - 1]['content'] = $content;
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private static function withoutMarker(array $message): array
    {
        $content = $message['content'] ?? null;
        if (!\is_array($content)) {
            return $message;
        }

        $touched = false;
        foreach ($content as $index => $block) {
            if (\is_array($block) && \array_key_exists('cache_hint', $block)) {
                unset($content[$index]['cache_hint']);
                $touched = true;
            }
        }

        if ($touched) {
            $message['content'] = array_values($content);
        }

        return $message;
    }

    /** @return list<mixed> */
    private static function blocks(mixed $raw): array
    {
        if (\is_string($raw)) {
            return [['type' => 'text', 'text' => $raw]];
        }

        return \is_array($raw) ? array_values($raw) : [];
    }
}
