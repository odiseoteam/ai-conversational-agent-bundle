<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Agent;

use Odiseo\AiConversationalAgentBundle\Budget\BudgetPolicy;
use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Capability\ToolContext;
use Odiseo\AiConversationalAgentBundle\Config\AgentConfigProviderInterface;
use Odiseo\AiConversationalAgentBundle\Execution\ToolExecutor;
use Odiseo\AiConversationalAgentBundle\Execution\ToolSurface;
use Odiseo\AiConversationalAgentBundle\Execution\TurnScope;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Grounding\ForcedRead;
use Odiseo\AiConversationalAgentBundle\Grounding\GroundingResolver;
use Odiseo\AiConversationalAgentBundle\Memory\ExtractMemory;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryFact;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryRuntime;
use Odiseo\AiConversationalAgentBundle\Prompt\ContextBlockBuilder;
use Odiseo\AiConversationalAgentBundle\Prompt\PromptAssembler;
use Odiseo\AiConversationalAgentBundle\Prompt\StaticPromptBuilder;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProviderInterface;
use Odiseo\AiConversationalAgentBundle\Provider\Request\SystemBlock;
use Odiseo\AiConversationalAgentBundle\Provider\Request\ToolChoice;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiConversationalAgentBundle\Provider\Response\StopReason;
use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TextChunk;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\ToolCallStarted;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\ToolInputChunk;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TurnFinished;
use Odiseo\AiConversationalAgentBundle\Session\CurrentSession;
use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use Odiseo\AiConversationalAgentBundle\Streaming\AgentEvent;
use Odiseo\AiConversationalAgentBundle\Streaming\EventType;
use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;
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
 * With eager dispatch a tool call runs the moment its arguments finish streaming, while the
 * model is still writing the rest of the round (StreamedRound); otherwise the round's calls
 * run after it, one after another. PHP can fan them out — Symfony's HTTP client multiplexes,
 * and Fibers are there — and the dispatch seam is StreamedRound::run; running them in parallel
 * is a decision that has not been taken yet, not something the language prevents.
 */
final class AgentLoop
{
    public function __construct(
        private readonly AgentConfigProviderInterface $config,
        private readonly ModelProviderInterface $provider,
        private readonly CapabilityRegistry $capabilities,
        private readonly ToolExecutor $executor,
        private readonly ToolSurface $toolSurface,
        private readonly StaticPromptBuilder $staticPrompt,
        private readonly ContextBlockBuilder $contextBlock,
        private readonly Fence $fence,
        private readonly MemoryRuntime $memory,
        private readonly BudgetPolicy $budget,
        private readonly ContextProviderInterface $context = new NullContextProvider(),
        private readonly LoggerInterface $logger = new NullLogger(),
        /** Runs the memory extraction; the turn's provider when null. */
        private readonly ?ModelProviderInterface $memoryProvider = null,
        private readonly CurrentSession $current = new CurrentSession(),
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
        $this->current->enter($session->sessionId);
        // The settings in force for the whole turn, read once.
        $config = $this->config->agentConfig();
        $now = $session->localNow();
        $clock = $now ?? new \DateTimeImmutable();

        $facts = $this->memory->tierOne($session->principalId);
        $context = $this->contextBlock->build(
            $this->context->contextPayload($session, $state),
            $facts,
            $now,
            $config->maxContextChars,
        );
        // What the model can do decides what is asked of it: no markers without caching, no
        // thinking without it, no eager dispatch without the deltas it reads.
        $can = $this->provider->capabilities($config->model);
        $system = PromptAssembler::systemBlocks($this->staticPrompt->buildFor($can), $context);
        if (!$can->promptCaching) {
            $system = array_map(static fn (SystemBlock $block): SystemBlock => new SystemBlock($block->text), $system);
        }
        $tools = $this->toolSurface->toolsFor($can);

        $scope = new TurnScope(replyInChips: !$can->textBeforeToolCalls);
        $toolContext = new ToolContext(
            session: $session,
            state: $state,
            fence: $this->fence,
            limits: $config->limits,
            scope: $scope,
        );

        $forced = GroundingResolver::resolve(
            $this->capabilities->groundingRules(),
            Transcript::latestUserText($messages),
            $state,
        );

        $canForce = $can->forcedToolChoice;
        if (null !== $forced && !$canForce) {
            $this->prefetch($forced, $toolContext, $messages);
        }

        $usage = new Usage();
        $rounds = 0;
        $stopReason = null;
        $lastPromptTokens = 0;
        $lastUsage = null;
        /** @var array<string, ToolOutcome> $settled */
        $settled = [];

        try {
            for ($round = 0; $round <= $config->maxToolIterations; ++$round) {
                $exceeded = $this->budget->exceeded($session->sessionId, $clock);
                if (null !== $exceeded) {
                    $this->logger->warning('turn stopped on the {scope} budget', [
                        'scope' => $exceeded->value,
                        'session' => $session->sessionTag(),
                    ]);
                    yield AgentEvent::error('budget_exceeded', 'This conversation has reached its limit for now. Please try again later.', ['limit' => $exceeded->value]);
                    $stopReason = 'budget';
                    break;
                }

                $forceText = $round === $config->maxToolIterations;
                $toolChoice = match (true) {
                    $forceText => ToolChoice::none(),
                    0 === $round && null !== $forced && $canForce => ToolChoice::forced($forced->rule->tool),
                    default => ToolChoice::auto(),
                };

                $request = new TurnRequest(
                    model: $config->model,
                    system: $system,
                    // The marker is skipped on a non-automatic round: the tool choice keys the
                    // cached span, so an entry written under a forced round is unreadable by
                    // the automatic rounds that follow.
                    messages: PromptAssembler::requestMessages(
                        $messages,
                        $can->promptCaching && $config->rollingConversationCache && $toolChoice->isAuto(),
                    ),
                    tools: $tools,
                    toolChoice: $toolChoice,
                    maxTokens: $config->maxTokens,
                    thinkingEffort: $can->thinking ? $config->thinkingEffort : null,
                    timeoutSeconds: $config->requestTimeoutSeconds,
                    cacheTools: $can->promptCaching,
                    cacheKey: $session->sessionTag(),
                );

                $response = null;
                // What the stream shows before the round is over — a call's status line the
                // moment it is complete, a presentation call's partial frames, and with eager
                // dispatch the call itself — is StreamedRound's; the join below picks up the
                // rest. Waiting for the whole round instead means the local reads this
                // deployment's tools do resolve before a person ever sees a line.
                $streamed = new StreamedRound($this->executor, $toolContext, $config->eagerToolDispatch && $can->toolInputDeltas && !$forceText);
                // A round that ends without TurnFinished has no usage, but its tokens were spent:
                // the finally charges an estimate so the budgets still count it. It stays
                // `client_gone` when the stream is dropped while suspended (the person left).
                $cut = 'client_gone';
                $received = false;
                $streamedChars = 0;
                try {
                    foreach ($this->provider->stream($request) as $event) {
                        $received = true;
                        if ($event instanceof TextChunk) {
                            $streamedChars += \strlen($event->text);
                            if ('' !== trim($event->text)) {
                                $scope->markText();
                            }
                            yield AgentEvent::textDelta($event->text);
                            continue;
                        }

                        if ($event instanceof ToolCallStarted) {
                            $streamed->started($event->id, $event->tool);
                            continue;
                        }

                        if ($event instanceof ToolInputChunk) {
                            $streamedChars += \strlen($event->partialJson);
                            yield from $streamed->chunk($event->id, $event->tool, $event->partialJson);
                            $settled = $streamed->settled();
                            continue;
                        }

                        if ($event instanceof TurnFinished) {
                            $response = $event->response;
                        }
                    }
                    $cut = 'incomplete';
                } catch (\Throwable $failed) {
                    $cut = 'error';

                    throw $failed;
                } finally {
                    // A request rejected before streaming anything back was not billed.
                    if (null === $response && $received) {
                        $this->chargeEstimate($session, $config->model, $round, $cut, $lastUsage, $request, $streamedChars, $clock);
                    }
                }

                if (null === $response) {
                    $stopReason = 'incomplete';
                    break;
                }

                $usage = $usage->plus($response->usage);
                ++$rounds;
                $lastPromptTokens = $response->usage->promptTokens();
                $lastUsage = $response->usage;
                $this->budget->charge($session->sessionId, $config->model, $response->usage, $clock);
                $this->log($session, $round, $response, $config->model);

                $stopReason = $response->stopReason?->value;
                if (StopReason::MaxTokens === $response->stopReason) {
                    $this->logger->warning('round cut at the output token limit', ['session' => $session->sessionTag(), 'round' => $round]);
                }
                if (null !== $assistant = $response->assistantMessage()) {
                    $messages[] = $assistant;
                }

                if ([] === $response->toolUses || $forceText) {
                    break;
                }

                $blocks = [];
                $closesTurn = $config->closeOnPresentation;
                $chipsSettled = false;

                // The calls eager dispatch did not reach (dispatch off, or a buffer that never
                // parsed) run now from the canonical arguments; without eager dispatch every
                // call is announced before the first one runs, so the person sees the whole
                // fan-out rather than one line at a time.
                $pending = array_values(array_filter(
                    $response->toolUses,
                    static fn ($call): bool => null === $streamed->outcome($call->id),
                ));
                if (!$config->eagerToolDispatch) {
                    foreach ($pending as $call) {
                        yield $this->executor->toolCallEvent($call->name, $call->id, $call->input);
                    }
                }
                foreach ($pending as $call) {
                    foreach ($streamed->run($call->id, $call->name, $call->input) as $produced) {
                        // Already announced above when dispatch is off.
                        if (!$config->eagerToolDispatch && EventType::ToolCall === $produced->type) {
                            continue;
                        }
                        yield $produced;
                    }
                }
                $settled = $streamed->settled();

                foreach ($response->toolUses as $call) {
                    $outcome = $settled[$call->id];
                    $blocks[] = Transcript::toolResultBlock($call->id, $outcome);
                    $closesTurn = $closesTurn && $this->executor->endsClean($call->name, $outcome);
                    $chipsSettled = $chipsSettled || $this->executor->settlesChips($call->name, $outcome);
                }

                $messages[] = ['role' => 'user', 'content' => $blocks];
                $settled = [];

                // A round whose calls all rendered leaves the model nothing to add; asking for
                // a closing line would only cost a round the person waits through.
                if ($closesTurn && $chipsSettled) {
                    $stopReason = 'end_turn';
                    break;
                }
            }
        } finally {
            Transcript::closeOpenToolUses($messages, $settled);
        }

        // Clearing old results under replayed thinking is a 400 on the models that check it.
        $cleared = $can->editableHistory || !$can->thinking || null === $config->thinkingEffort
            ? Transcript::compact($messages, $lastPromptTokens, $config->compactHistoryAboveTokens)
            : 0;

        yield AgentEvent::turnComplete(
            $stopReason,
            $usage->toArray(),
            (int) round((microtime(true) - $startedAt) * 1000),
            $cleared,
            $rounds,
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
        return $this->extractMemory($this->memoryOf($messages, $session));
    }

    /**
     * The finished turn as what is left to extract from it, for a host that extracts later.
     *
     * @param list<array<string, mixed>> $messages
     */
    public function memoryOf(array $messages, SessionContext $session): ExtractMemory
    {
        return new ExtractMemory($session->principalId, $session->sessionId, Transcript::text(Transcript::latestExchange($messages)));
    }

    /**
     * Throws when the extraction fails, so whoever runs it decides what a failure costs.
     *
     * @return list<MemoryFact>
     */
    public function extractMemory(ExtractMemory $turn): array
    {
        $session = new SessionContext($turn->sessionId, $turn->principalId);
        $this->current->enter($turn->sessionId);
        $model = $this->config->agentConfig()->memoryModel;

        return $this->memory->extract(
            $this->memoryProvider ?? $this->provider,
            $turn->principalId,
            $session->sessionTag(),
            $turn->exchange,
            function (ProviderResponse $response) use ($session, $model): void {
                $this->budget->charge($session->sessionId, $model, $response->usage, new \DateTimeImmutable());
                $this->log($session, 'memory', $response, $model);
            },
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

    /**
     * The prompt only grows within a turn, so the previous round's usage is a floor for this
     * one, priced with its own split of cached and uncached tokens. The first round counts the
     * request's characters, four to a token: the cacheable prefix as cache reads, the rest as
     * uncached input.
     */
    private function chargeEstimate(SessionContext $session, string $model, int $round, string $cut, ?Usage $previous, TurnRequest $request, int $streamedChars, \DateTimeImmutable $clock): void
    {
        $output = intdiv($streamedChars, 4);
        if (null !== $previous) {
            $usage = new Usage($previous->inputTokens, $output, $previous->cacheCreationTokens, $previous->cacheReadTokens);
        } else {
            [$cached, $uncached] = self::requestChars($request);
            $usage = new Usage(inputTokens: intdiv($uncached, 4), outputTokens: $output, cacheReadTokens: intdiv($cached, 4));
        }
        try {
            $usd = $this->budget->charge($session->sessionId, $model, $usage, $clock);
        } catch (\Throwable $failed) {
            // Runs while the round's own error unwinds: a failed charge must not replace it.
            $this->logger->error('the estimated charge failed', ['session' => $session->sessionTag(), 'exception' => $failed]);

            return;
        }

        $this->logger->warning('round charged on an estimate', [
            'session' => $session->sessionTag(),
            'round' => $round,
            'reason' => $cut,
            'model' => $model,
            'usage' => $usage->toArray(),
            'usd' => $usd,
        ]);
    }

    /** @return array{int, int} the characters of the cacheable prefix and of the rest */
    private static function requestChars(TurnRequest $request): array
    {
        $cached = 0;
        $uncached = \strlen((string) json_encode($request->messages));
        foreach ($request->system as $block) {
            if ($block->cacheHint) {
                $cached += \strlen($block->text);
            } else {
                $uncached += \strlen($block->text);
            }
        }
        $tools = 0;
        foreach ($request->tools as $tool) {
            $tools += \strlen($tool->description) + \strlen((string) json_encode($tool->inputSchema));
        }

        return $request->cacheTools ? [$cached + $tools, $uncached] : [$cached, $uncached + $tools];
    }

    private function log(SessionContext $session, int|string $round, ProviderResponse $response, string $model): void
    {
        $this->logger->info('model call', [
            'session' => $session->sessionTag(),
            'round' => $round,
            'model' => $model,
            'stop_reason' => $response->stopReason?->value,
            'usage' => $response->usage->toArray(),
            'tool_calls' => array_map(static fn ($call): string => $call->name, $response->toolUses),
        ]);
    }
}
