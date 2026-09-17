<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Memory;

use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Provider\ModelProvider;
use Odiseo\AiConversationalAgentBundle\Provider\Request\SystemBlock;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

    public function __construct(
        private readonly MemoryStore $store,
        private readonly AgentConfig $config,
        private readonly Fence $fence,
        private readonly string $extractionPrompt,
        private readonly MemoryWriteFilter $writeFilter = new MemoryWriteFilter(),
        private readonly LoggerInterface $logger = new NullLogger(),
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

        $key = $this->fence->sanitizeText((string) ($input['key'] ?? ''), self::KEY_MAX_CHARS);
        $value = $this->fence->sanitizeText((string) ($input['value'] ?? ''), self::VALUE_MAX_CHARS);
        $category = MemoryCategory::tryFrom((string) ($input['category'] ?? '')) ?? MemoryCategory::Preference;

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

        $query = $this->fence->sanitizeText((string) ($input['query'] ?? ''), 200);
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
     * Extract what the finished turn taught and store it. Runs once the reply has streamed;
     * never raises, because a memory failure must not surface as a failed turn. The caller
     * sees the model's response through $onResponse, to charge and log it like any round.
     *
     * @return list<MemoryFact>
     */
    public function extract(ModelProvider $provider, string $subject, string $sessionTag, string $transcript, ?\Closure $onResponse = null): array
    {
        if (!$this->config->enableMemory || '' === trim($transcript)) {
            return [];
        }

        try {
            $response = $provider->complete(new TurnRequest(
                model: $this->config->memoryModel,
                system: [new SystemBlock($this->extractionPrompt)],
                messages: [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => $this->fence->fencePayload($transcript, 8000)]],
                ]],
                maxTokens: 1024,
                thinkingEffort: null,
                timeoutSeconds: $this->config->requestTimeoutSeconds,
                cacheTools: false,
            ));
            if (null !== $onResponse) {
                $onResponse($response);
            }

            $written = [];
            foreach ($this->decodeFacts($response->text()) as $candidate) {
                $outcome = $this->save($subject, $sessionTag, $candidate);
                if (!$outcome->refused() && str_starts_with($outcome->resultText, 'Saved')) {
                    $written[] = $candidate['key'];
                }
            }

            return $this->live($this->store->all($subject));
        } catch (\Throwable $error) {
            $this->logger->warning('memory extraction failed', ['session' => $sessionTag, 'exception' => $error]);

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeFacts(string $text): array
    {
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if (false === $start || false === $end || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!\is_array($decoded)) {
            return [];
        }

        $facts = [];
        foreach ($decoded as $row) {
            if (\is_array($row) && isset($row['key'], $row['value'])) {
                $facts[] = $row;
            }
        }

        return $facts;
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
