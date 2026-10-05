<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Memory;

use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProvider;
use Odiseo\AiConversationalAgentBundle\Provider\Request\SystemBlock;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;

/**
 * What the agent remembers between sessions: the two memory tools, the facts injected into
 * every request, and the extraction that runs once a reply is out.
 *
 * Nothing here decides what is worth keeping — that is the extraction prompt, which belongs to
 * the vertical. What is here is what may be kept at all, and the shape it is kept in.
 */
final class MemoryRuntime
{
    private const KEY_MAX_CHARS = 64;
    private const VALUE_MAX_CHARS = 200;
    private const RECORD_FACT = 'record_fact';
    private const MAX_NEW_FACTS = 3;

    public function __construct(
        private readonly MemoryStore $store,
        private readonly AgentConfig $config,
        private readonly Fence $fence,
        private readonly string $extractionPrompt,
        private readonly MemoryWriteFilter $writeFilter = new MemoryWriteFilter(),
    ) {
    }

    public function store(): MemoryStore
    {
        return $this->store;
    }

    /**
     * The facts a request carries: every constraint, then the most recent of the rest, up to
     * the cap. Anything past its retention is neither injected nor recalled.
     *
     * @return list<MemoryFact>
     */
    public function tierOne(string $subject): array
    {
        if (!$this->config->enableMemory) {
            return [];
        }

        $facts = $this->live($this->store->all($subject));

        $constraints = array_values(array_filter($facts, static fn (MemoryFact $f): bool => MemoryCategory::Constraint === $f->category));
        $rest = array_values(array_filter($facts, static fn (MemoryFact $f): bool => MemoryCategory::Constraint !== $f->category));

        return \array_slice([...$constraints, ...$rest], 0, $this->config->memoryTierOneCap);
    }

    /** @param array<string, mixed> $input */
    public function save(string $subject, string $sessionTag, array $input): ToolOutcome
    {
        if (!$this->config->enableMemory) {
            return ToolOutcome::error('Memory is off in this deployment; do not offer to remember anything.');
        }

        $key = $this->fence->sanitizeText(Scalar::string($input['key'] ?? null), self::KEY_MAX_CHARS);
        $value = $this->fence->sanitizeText(Scalar::string($input['value'] ?? null), self::VALUE_MAX_CHARS);
        $category = MemoryCategory::tryFrom(Scalar::string($input['category'] ?? null)) ?? MemoryCategory::Preference;

        if ('' === $key || '' === $value) {
            return ToolOutcome::error('save_memory needs a short key and a value.');
        }

        if (!$this->writeFilter->allows($value) || !$this->writeFilter->allows($key)) {
            // Nothing is stored and the person hears nothing about it: an identifier is not a
            // failure to report, it is a thing this agent does not keep.
            return new ToolOutcome('Not stored: that is an identifier or a detail this assistant does not keep. Carry on with the conversation and do not mention it.');
        }

        $this->store->save($subject, new MemoryFact(
            $key,
            $value,
            $category,
            new \DateTimeImmutable(),
            $sessionTag,
        ));

        return new ToolOutcome(\sprintf('Saved "%s".', $key));
    }

    /** @param array<string, mixed> $input */
    public function recall(string $subject, array $input): ToolOutcome
    {
        if (!$this->config->enableMemory) {
            return ToolOutcome::error('Memory is off in this deployment.');
        }

        $query = $this->fence->sanitizeText(Scalar::string($input['query'] ?? null), 200);
        $facts = $this->live($this->store->search($subject, $query, 10));

        $payload = array_map(static fn (MemoryFact $fact): array => $fact->toPayload(), $facts);

        return new ToolOutcome($this->fence->fencePayload(
            ['saved_memory' => [] === $payload ? 'none' : $payload],
            $this->config->limits->maxFencedChars,
        ));
    }

    public function forget(string $subject, string $key): bool
    {
        return $this->store->forget($subject, $key);
    }

    /**
     * Extract what the finished turn taught and store it. Runs once the reply has streamed; a
     * failure is raised to the caller, who knows whether anyone is left to retry it. The caller
     * sees the model's response through $onResponse, to charge and log it like any round.
     *
     * The model records each fact through `record_fact`, against the facts already saved. A
     * proposal under a saved key is the update the prompt asks for; one restating a saved
     * value is dropped. At most three are kept.
     *
     * @return list<MemoryFact>
     */
    public function extract(ModelProvider $provider, string $subject, string $sessionTag, string $transcript, ?\Closure $onResponse = null): array
    {
        if (!$this->config->enableMemory || '' === trim($transcript)) {
            return [];
        }

        $existing = $this->live($this->store->all($subject));
        $response = $provider->complete(new TurnRequest(
            model: $this->config->memoryModel,
            system: [new SystemBlock($this->extractionPrompt)],
            messages: [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => "Already saved facts:\n".$this->render($existing)."\n\nConversation:\n".$this->fence->fencePayload($transcript, 8000)]],
            ]],
            tools: [self::recordFactTool()],
            maxTokens: 600,
            thinkingEffort: null,
            timeoutSeconds: $this->config->requestTimeoutSeconds,
            cacheTools: false,
        ));
        if (null !== $onResponse) {
            $onResponse($response);
        }

        $held = [];
        foreach ($existing as $fact) {
            $held[$fact->key] = self::normalize($fact->value);
        }
        $written = 0;
        foreach ($response->toolUses as $call) {
            if (self::RECORD_FACT !== $call->name || $written >= self::MAX_NEW_FACTS) {
                continue;
            }
            $key = trim(Scalar::string($call->input['key'] ?? null));
            $value = self::normalize(Scalar::string($call->input['value'] ?? null));
            $current = $held[$key] ?? null;
            if ($current === $value || (null === $current && $this->restates($value, $held))) {
                continue;
            }
            $outcome = $this->save($subject, $sessionTag, $call->input);
            if (!$outcome->refused() && str_starts_with($outcome->resultText, 'Saved')) {
                $held[$key] = $value;
                ++$written;
            }
        }

        return $this->live($this->store->all($subject));
    }

    private static function recordFactTool(): ToolSpec
    {
        return new ToolSpec(
            self::RECORD_FACT,
            'Record one new durable fact about the user.',
            [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'maxLength' => self::KEY_MAX_CHARS],
                    'value' => ['type' => 'string', 'maxLength' => self::VALUE_MAX_CHARS],
                    'category' => ['type' => 'string', 'enum' => array_map(static fn (MemoryCategory $c): string => $c->value, MemoryCategory::cases())],
                ],
                'required' => ['key', 'value', 'category'],
                'additionalProperties' => false,
            ],
            wantsStatusLine: false,
        );
    }

    /** @param list<MemoryFact> $facts */
    private function render(array $facts): string
    {
        if ([] === $facts) {
            return 'No saved facts.';
        }

        return implode("\n", array_map(
            fn (MemoryFact $fact): string => \sprintf('- %s: %s [%s]', $fact->key, $this->fence->sanitizeText($fact->value, self::VALUE_MAX_CHARS), $fact->category->value),
            $facts,
        ));
    }

    private static function normalize(string $value): string
    {
        return implode(' ', preg_split('/\s+/u', mb_strtolower(trim($value))) ?: []);
    }

    /**
     * Whether a value states a fact already held under another key: one contains the other, or
     * their words overlap by 60% or more.
     *
     * @param array<string, string> $held
     */
    private function restates(string $value, array $held): bool
    {
        $words = static fn (string $text): array => array_values(array_filter(array_unique(array_map(
            static fn (string $word): string => trim($word, ".,;:!?'\"()"),
            explode(' ', $text),
        ))));
        $mine = $words($value);
        foreach ($held as $seen) {
            if ('' !== $value && '' !== $seen && (str_contains($seen, $value) || str_contains($value, $seen))) {
                return true;
            }
            $theirs = $words($seen);
            $union = \count(array_unique([...$mine, ...$theirs]));
            if (0 !== $union && \count(array_intersect($mine, $theirs)) / $union >= 0.6) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MemoryFact> $facts
     *
     * @return list<MemoryFact>
     */
    private function live(array $facts): array
    {
        $days = $this->config->memoryRetentionDays;
        if (null === $days) {
            return $facts;
        }

        $cutoff = new \DateTimeImmutable(\sprintf('-%d days', $days));

        return array_values(array_filter(
            $facts,
            static fn (MemoryFact $fact): bool => null === $fact->updatedAt || $fact->updatedAt >= $cutoff,
        ));
    }
}
