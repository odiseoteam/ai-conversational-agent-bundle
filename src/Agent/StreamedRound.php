<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Agent;

use Odiseo\AiAgentBundle\Capability\ToolContext;
use Odiseo\AiAgentBundle\Execution\ToolExecutor;
use Odiseo\AiAgentBundle\Execution\ToolSurface;
use Odiseo\AiAgentBundle\Fencing\Sanitizer;
use Odiseo\AiAgentBundle\Streaming\AgentEvent;
use Odiseo\AiAgentBundle\Streaming\PartialJson;
use Odiseo\AiAgentBundle\Streaming\ToolOutcome;

/**
 * One model round while it streams: each tool call's arguments buffered as they arrive, and
 * what the host gets to see before the round is over.
 *
 * Three things come out of the buffer, in this order, on every chunk:
 *  - the call's status line, once it is complete (a `progress` event);
 *  - a `ui_partial` frame when the call is a presentation component that renders partially
 *    and something visible changed since the last frame;
 *  - with eager dispatch, the call itself: the moment the buffer parses as complete JSON the
 *    arguments are final, so the tool runs then — while the model is still writing the rest
 *    of the round — and its `tool_call`, its own events and its `tool_result` go out at once.
 *
 * The post-stream join (`AgentLoop`) runs whatever was not dispatched here and builds the
 * tool-result blocks in the response's canonical order; every call runs exactly once.
 */
final class StreamedRound
{
    /** @var array<string, array{tool: string, buffer: string, statusShown: bool, frameKey: ?string, closed: bool}> */
    private array $calls = [];

    /** @var array<string, ToolOutcome> */
    private array $settled = [];

    public function __construct(
        private readonly ToolExecutor $executor,
        private readonly ToolContext $context,
        private readonly bool $eagerDispatch,
    ) {
    }

    public function started(string $id, string $tool): void
    {
        $this->calls[$id] ??= ['tool' => $tool, 'buffer' => '', 'statusShown' => false, 'frameKey' => null, 'closed' => false];
    }

    /**
     * @return \Generator<int, AgentEvent>
     */
    public function chunk(string $id, string $tool, string $partialJson): \Generator
    {
        $this->started($id, $tool);
        $call = &$this->calls[$id];
        if ($call['closed']) {
            return;
        }
        $call['buffer'] .= $partialJson;

        if (!$call['statusShown']) {
            $decoded = PartialJson::decode($call['buffer']);
            $status = Sanitizer::label($decoded[ToolSurface::STATUS_FIELD] ?? '', ToolSurface::STATUS_MAX_CHARS);
            if ('' !== $status) {
                $call['statusShown'] = true;
                yield AgentEvent::progress($status, $call['tool']);
            }
        }

        $frame = $this->executor->partialFrame($call['tool'], $call['buffer'], $this->context);
        if (null !== $frame && $frame->key !== $call['frameKey']) {
            $call['frameKey'] = $frame->key;
            yield AgentEvent::uiPartial($frame->component, $frame->payload, $id);
        }

        // A tool block's input is one JSON object: the first time the buffer parses whole, the
        // block has closed and nothing more comes for it.
        $complete = json_decode($call['buffer'], true);
        if (!\is_array($complete)) {
            return;
        }
        $call['closed'] = true;

        if ($this->eagerDispatch) {
            yield from $this->run($id, $call['tool'], $complete);
        }
    }

    /**
     * Announce, run and report one call. Used mid-stream by eager dispatch and by the join for
     * the calls it did not reach.
     *
     * @param array<string, mixed> $input
     *
     * @return \Generator<int, AgentEvent>
     */
    public function run(string $id, string $tool, array $input): \Generator
    {
        yield $this->executor->toolCallEvent($tool, $id, $input);

        $outcome = $this->executor->execute($tool, $input, $this->context);
        $this->settled[$id] = $outcome;

        foreach ($outcome->events as $produced) {
            yield $produced->withStreamId($id);
        }

        yield AgentEvent::toolResult(
            $tool,
            $id,
            Sanitizer::truncateDisplay($outcome->resultText, 140),
            $outcome->isError,
            null !== $outcome->blocked ? 'blocked' : ($outcome->isError ? 'error' : 'ok'),
            $outcome->blocked,
        );
    }

    public function outcome(string $id): ?ToolOutcome
    {
        return $this->settled[$id] ?? null;
    }

    /** @return array<string, ToolOutcome> */
    public function settled(): array
    {
        return $this->settled;
    }
}
