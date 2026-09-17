<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Agent;

use Odiseo\AiAgentBundle\Budget\BudgetPolicy;
use Odiseo\AiAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiAgentBundle\Capability\ToolContext;
use Odiseo\AiAgentBundle\Config\AgentConfig;
use Odiseo\AiAgentBundle\Execution\ChipComponent;
use Odiseo\AiAgentBundle\Execution\ToolExecutor;
use Odiseo\AiAgentBundle\Execution\ToolSurface;
use Odiseo\AiAgentBundle\Execution\TurnScope;
use Odiseo\AiAgentBundle\Fencing\Fence;
use Odiseo\AiAgentBundle\Fencing\Sanitizer;
use Odiseo\AiAgentBundle\Grounding\ForcedRead;
use Odiseo\AiAgentBundle\Grounding\GroundingResolver;
use Odiseo\AiAgentBundle\Memory\MemoryFact;
use Odiseo\AiAgentBundle\Memory\MemoryRuntime;
use Odiseo\AiAgentBundle\Prompt\ContextBlockBuilder;
use Odiseo\AiAgentBundle\Prompt\PromptAssembler;
use Odiseo\AiAgentBundle\Prompt\StaticPromptBuilder;
use Odiseo\AiAgentBundle\Provider\ModelProvider;
use Odiseo\AiAgentBundle\Provider\Request\ToolChoice;
use Odiseo\AiAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiAgentBundle\Provider\Response\Usage;
use Odiseo\AiAgentBundle\Provider\Stream\TextChunk;
use Odiseo\AiAgentBundle\Provider\Stream\ToolCallStarted;
use Odiseo\AiAgentBundle\Provider\Stream\ToolInputChunk;
use Odiseo\AiAgentBundle\Provider\Stream\TurnFinished;
use Odiseo\AiAgentBundle\Session\SessionContext;
use Odiseo\AiAgentBundle\Session\TurnState;
use Odiseo\AiAgentBundle\Streaming\AgentEvent;
use Odiseo\AiAgentBundle\Streaming\PartialJson;
use Odiseo\AiAgentBundle\Streaming\ToolOutcome;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * One turn: a model call per round, the tools it asks for, and the events the host renders.
 *
 * The turn's first round is pinned to a grounding read when one fires, the last round is
 * forced to answer without tools, the cache breakpoint rolls through the conversation on
 * automatic rounds only, and a round of clean presentation calls ends the turn without asking
 * the model for a closing line.
 *
 * Tool calls in a round run one after another. PHP can fan them out — Symfony's HTTP client
 * multiplexes, and Fibers are there — and the dispatch seam is this method; running them in
 * parallel is a decision that has not been taken yet, not something the language prevents.
 */
final class AgentLoop
{
    public function __construct(
        private readonly AgentConfig $config,
        private readonly ModelProvider $provider,
        private readonly CapabilityRegistry $capabilities,
        private readonly ToolExecutor $executor,
        private readonly ToolSurface $toolSurface,
        private readonly StaticPromptBuilder $staticPrompt,
        private readonly ContextBlockBuilder $contextBlock,
        private readonly Fence $fence,
        private readonly MemoryRuntime $memory,
        private readonly BudgetPolicy $budget,
        private readonly ContextProvider $context = new NullContextProvider(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Run one turn. $messages ends with the caller's message and is extended in place with the
     * turn's assistant messages and tool results, so the host stores it as it stands.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return \Generator<int, AgentEvent>
     */
    public function streamTurn(array &$messages, SessionContext $session, TurnState $state): \Generator
    {
        $startedAt = microtime(true);
        $now = $session->localNow();
        $clock = $now ?? new \DateTimeImmutable();

        $facts = $this->memory->tierOne($session->principalId);
        $context = $this->contextBlock->build(
            $this->context->contextPayload($session, $state),
            $facts,
            $now,
            $this->config->maxContextChars,
        );
        $system = PromptAssembler::systemBlocks($this->staticPrompt->build(), $context);
        $tools = $this->toolSurface->tools();

        $scope = new TurnScope();
        $toolContext = new ToolContext(
            session: $session,
            state: $state,
            fence: $this->fence,
            limits: $this->config->limits,
            scope: $scope,
        );

        $forced = GroundingResolver::resolve(
            $this->capabilities->groundingRules(),
            Transcript::latestUserText($messages),
            $state,
        );

        $canForce = $this->provider->capabilities()->forcedToolChoice;
        if (null !== $forced && !$canForce) {
            $this->prefetch($forced, $toolContext, $messages);
        }

        $usage = new Usage();
        $stopReason = null;
        $lastPromptTokens = 0;
        /** @var array<string, ToolOutcome> $settled */
        $settled = [];

        try {
            for ($round = 0; $round <= $this->config->maxToolIterations; ++$round) {
                $exceeded = $this->budget->exceeded($session->sessionId, $clock);
                if (null !== $exceeded) {
                    $this->logger->warning('turn stopped on the {scope} budget', [
                        'scope' => $exceeded->value,
                        'session' => $session->sessionTag(),
                    ]);
                    yield AgentEvent::error('This conversation has reached its limit for now. Please try again later.');
                    $stopReason = 'budget';
                    break;
                }

                $forceText = $round === $this->config->maxToolIterations;
                $toolChoice = match (true) {
                    $forceText => ToolChoice::none(),
                    0 === $round && null !== $forced && $canForce => ToolChoice::forced($forced->rule->tool),
                    default => ToolChoice::auto(),
                };

                $request = new TurnRequest(
                    model: $this->config->model,
                    system: $system,
                    // The marker is skipped on a non-automatic round: the tool choice keys the
                    // cached span, so an entry written under a forced round is unreadable by
                    // the automatic rounds that follow.
                    messages: PromptAssembler::requestMessages(
                        $messages,
                        $this->config->rollingConversationCache && $toolChoice->isAuto(),
                    ),
                    tools: $tools,
                    toolChoice: $toolChoice,
                    maxTokens: $this->config->maxTokens,
                    thinkingEffort: $this->config->thinkingEffort,
                    timeoutSeconds: $this->config->requestTimeoutSeconds,
                );

                $response = null;
                // A tool's status line (ToolSurface::withStatus puts it first in the schema)
                // is worth showing well before the round finishes: waiting for the whole round
                // — every tool call in it, plus any closing text — means the local reads this
                // deployment's tools do resolve before a person ever sees the line. Each call's
                // arguments are buffered as they stream and tolerantly parsed (PartialJson) so
                // the status can be read the moment it is complete, without waiting on whatever
                // argument the model happens to write after it.
                $toolNames = [];
                $partials = [];
                $statusShown = [];

                foreach ($this->provider->stream($request) as $event) {
                    if ($event instanceof TextChunk) {
                        yield AgentEvent::textDelta($event->text);
                        continue;
                    }

                    if ($event instanceof ToolCallStarted) {
                        $toolNames[$event->id] = $event->tool;
                        continue;
                    }

                    if ($event instanceof ToolInputChunk) {
                        $partials[$event->id] = ($partials[$event->id] ?? '').$event->partialJson;
                        if (isset($statusShown[$event->id])) {
                            continue;
                        }

                        $decoded = PartialJson::decode($partials[$event->id]);
                        $status = Sanitizer::label($decoded[ToolSurface::STATUS_FIELD] ?? '', ToolSurface::STATUS_MAX_CHARS);
                        if ('' !== $status) {
                            $statusShown[$event->id] = true;
                            yield AgentEvent::progress($status, $toolNames[$event->id] ?? $event->tool);
                        }
                        continue;
                    }

                    if ($event instanceof TurnFinished) {
                        $response = $event->response;
                    }
                }

                if (null === $response) {
                    $stopReason = 'incomplete';
                    break;
                }

                $usage = $usage->plus($response->usage);
                $lastPromptTokens = $response->usage->promptTokens();
                $this->budget->charge($session->sessionId, $this->config->model, $response->usage, $clock);
                $this->log($session, $round, $response);

                $stopReason = $response->stopReason;
                if (null !== $assistant = $response->assistantMessage()) {
                    $messages[] = $assistant;
                }

                if ([] === $response->toolUses || $forceText) {
                    break;
                }

                $blocks = [];
                $settled = [];
                $closesTurn = $this->config->closeOnPresentation;
                $chipsInRound = false;

                // Every call the round asked for is announced before the first one runs, so
                // the person sees the whole fan-out rather than one line at a time.
                foreach ($response->toolUses as $call) {
                    yield $this->executor->toolCallEvent($call->name, $call->id, $call->input);
                }

                foreach ($response->toolUses as $call) {
                    $outcome = $this->executor->execute($call->name, $call->input, $toolContext);
                    $settled[$call->id] = $outcome;

                    foreach ($outcome->events as $produced) {
                        yield $produced;
                    }

                    yield AgentEvent::toolResult(
                        $call->name,
                        $call->id,
                        Sanitizer::truncateDisplay($outcome->resultText, 140),
                        $outcome->isError,
                        null !== $outcome->blocked ? 'blocked' : ($outcome->isError ? 'error' : 'ok'),
                        $outcome->blocked,
                    );

                    $blocks[] = Transcript::toolResultBlock($call->id, $outcome);
                    $closesTurn = $closesTurn && $this->executor->endsClean($call->name, $outcome);
                    $chipsInRound = $chipsInRound || (ChipComponent::TOOL === $call->name && !$outcome->refused());
                }

                $messages[] = ['role' => 'user', 'content' => $blocks];
                $settled = [];

                // A round whose calls all rendered leaves the model nothing to add; asking for
                // a closing line would only cost a round the person waits through.
                if ($closesTurn && $chipsInRound) {
                    $stopReason = 'end_turn';
                    break;
                }
            }
        } finally {
            Transcript::closeOpenToolUses($messages, $settled);
        }

        $cleared = Transcript::compact($messages, $lastPromptTokens, $this->config->compactHistoryAboveTokens);

        yield AgentEvent::turnComplete(
            $stopReason,
            $usage->toArray(),
            (int) round((microtime(true) - $startedAt) * 1000),
            $cleared,
        );
    }

    /**
     * Extract what the finished turn taught. Run it once the reply has streamed.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<MemoryFact>
     */
    public function updateMemory(array $messages, SessionContext $session): array
    {
        return $this->memory->extract(
            $this->provider,
            $session->principalId,
            $session->sessionTag(),
            Transcript::text(Transcript::latestExchange($messages)),
        );
    }

    /**
     * A provider that cannot pin a round to one tool gets the read done for it, and the result
     * arrives as a message above the caller's, introduced as the host's own work.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function prefetch(ForcedRead $forced, ToolContext $context, array &$messages): void
    {
        $intro = $forced->intro();
        if (null === $intro) {
            return;
        }

        try {
            $outcome = $this->executor->dispatch($forced->rule->tool, $forced->input, $context);
        } catch (\Throwable $failed) {
            $this->logger->warning('prefetch of {tool} failed', [
                'tool' => $forced->rule->tool,
                'session' => $context->session->sessionTag(),
                'exception' => $failed,
            ]);

            return;
        }

        $position = max(0, \count($messages) - 1);
        $messages = [
            ...\array_slice($messages, 0, $position),
            Transcript::userMessage($intro."\n".$outcome->resultText),
            ...\array_slice($messages, $position),
        ];
    }

    private function log(SessionContext $session, int $round, ProviderResponse $response): void
    {
        $this->logger->info('model call', [
            'session' => $session->sessionTag(),
            'round' => $round,
            'model' => $this->config->model,
            'stop_reason' => $response->stopReason,
            'usage' => $response->usage->toArray(),
            'tool_calls' => array_map(static fn ($call): string => $call->name, $response->toolUses),
        ]);
    }
}
