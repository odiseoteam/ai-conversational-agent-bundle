<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Config;

use Odiseo\AiAgentBundle\Capability\Limits;

/**
 * One deployment's settings. Per-request values travel in the SessionContext instead.
 *
 * Fields marked (prompt) render into the static prompt or the tool list, so they are constant
 * within a deployment: changing one at runtime re-reads the whole cached prefix on every
 * call. The rest steer the runtime only and never change prompt bytes.
 */
final readonly class AgentConfig
{
    /**
     * @param string $scope         (prompt) what this agent is for, in one clause
     * @param string $replyLanguage (prompt) the language the agent answers in, worded in English
     *                              because it completes an English sentence
     */
    public function __construct(
        // -- Identity (prompt)
        public string $brandName = 'the organisation',
        public string $assistantName = 'the assistant',
        public string $brandVoice = 'plain and specific',
        public string $audience = 'a visitor',
        public string $scope = 'the organisation and what it offers',
        public string $replyLanguage = 'the language of the visitor\'s most recent message',

        // -- Models. The turn loop runs on $model and post-turn extraction on $memoryModel.
        public string $model = 'claude-sonnet-5',
        public string $memoryModel = 'claude-haiku-4-5-20251001',
        public ?ThinkingEffort $thinkingEffort = ThinkingEffort::Low,

        // -- Budgets. The iteration cap is a runaway guard: a multi-part request has to finish
        // well inside it, because once the cap forces a tool-less round the model tends to
        // describe steps it never performed.
        public int $maxTokens = 2048,
        public int $maxToolIterations = 8,
        public float $requestTimeoutSeconds = 120.0,

        // -- Spend caps, in USD. The ledger stops a turn that would cross one. These are cost
        // controls: abuse is stopped by the rate limiter at the edge, not here.
        public float $sessionBudgetUsd = 0.50,
        public float $dailyBudgetUsd = 20.0,

        // -- Latency switches, each independent so a problem can be bisected. Eager dispatch
        // starts a tool call while the round is still being written; the rolling cache turns
        // the prior rounds into cache reads; closing on presentation ends the turn after a
        // round of clean presentation calls instead of asking for a closing line.
        public bool $eagerToolDispatch = true,
        public bool $rollingConversationCache = true,
        public bool $closeOnPresentation = true,

        // -- Memory: facts injected per request (every constraint, then the most recent), extra
        // write-filter patterns on top of the identifier defaults, and the age past which a
        // fact is neither injected nor recalled (null keeps facts).
        public bool $enableMemory = true,
        public int $memoryTierOneCap = 8,
        /** @var list<string> */
        public array $memoryBlockedPatterns = [],
        public ?int $memoryRetentionDays = null,

        // -- Caps.
        public Limits $limits = new Limits(),
        public int $maxContextChars = 2000,
        /**
         * The prompt size at which a turn ends by clearing the oldest tool results from the
         * stored conversation (0 never clears). A tenth of the model's window: cost and
         * latency grow with every round long before the window is the limit.
         */
        public int $compactHistoryAboveTokens = 100_000,
    ) {
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        $args = get_object_vars($this);

        return new self(...array_merge($args, $changes));
    }
}
