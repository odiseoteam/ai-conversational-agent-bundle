<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Tests\Fixture;

use Odiseo\AiAgentBundle\Capability\Capability;
use Odiseo\AiAgentBundle\Capability\PromptFragment;
use Odiseo\AiAgentBundle\Capability\PromptSection;
use Odiseo\AiAgentBundle\Capability\ToolContext;
use Odiseo\AiAgentBundle\Capability\ToolSpec;
use Odiseo\AiAgentBundle\Execution\DomainErrorMapper;
use Odiseo\AiAgentBundle\Execution\ExecutorWording;
use Odiseo\AiAgentBundle\Gate\ProvenanceGate;
use Odiseo\AiAgentBundle\Grounding\GroundingRule;
use Odiseo\AiAgentBundle\Grounding\Matcher;
use Odiseo\AiAgentBundle\Presentation\PresentationComponent;
use Odiseo\AiAgentBundle\Session\SeenRecord;
use Odiseo\AiAgentBundle\Session\TurnState;
use Odiseo\AiAgentBundle\Streaming\ToolOutcome;

/** A minimal vertical: one read, one gated write, one card, one grounding rule, one domain error. */
final class DirectoryCapability implements Capability, DomainErrorMapper
{
    /** @var array<string, int> tool => times execute() ran it */
    public array $runs = [];

    /** @param array<string, string> $records id => title */
    public function __construct(private readonly array $records = ['R-1' => 'Primero', 'R-2' => 'Segundo'])
    {
    }

    public function name(): string
    {
        return 'test.directory';
    }

    public function tools(): array
    {
        return [
            new ToolSpec('find_records', 'Find records.', [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string']],
                'required' => ['query'],
                'additionalProperties' => false,
            ]),
            new ToolSpec('pick_record', 'Pick one record; provenance-gated write.', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'string']],
                'required' => ['id'],
                'additionalProperties' => false,
            ]),
            new ToolSpec('break_things', 'Always fails.', [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ]),
            new ToolSpec('present_records', 'Show records.', [
                'type' => 'object',
                'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string']]],
                'required' => ['ids'],
                'additionalProperties' => false,
            ], wantsStatusLine: false),
        ];
    }

    public function promptFragments(): array
    {
        return [new PromptFragment(PromptSection::Tools, '- Buscá antes de responder.', priority: 5)];
    }

    public function groundingRules(): array
    {
        return [new GroundingRule(
            'directory',
            'find_records',
            static fn (string $text, TurnState $state): ?array => Matcher::matchesTermsAndCues($text, ['registro'], ['?'])
                ? ['query' => $text]
                : null,
            static fn (array $input): string => 'Prefetched:',
        )];
    }

    public function components(): array
    {
        return [new PresentationComponent(
            'present_records',
            'records',
            static function (array $input): array {
                $ids = array_values(array_map('strval', \is_array($input['ids'] ?? null) ? $input['ids'] : []));
                if ([] === $ids) {
                    throw new \InvalidArgumentException('ids is required.');
                }

                return ['ids' => $ids];
            },
            static function (array $payload, $context): array {
                $items = [];
                foreach ($payload['ids'] as $id) {
                    $record = $context->tools->state->seen($id);
                    if (null === $record) {
                        $context->note(ProvenanceGate::message($id, 'call find_records'));
                        continue;
                    }
                    $items[] = ['id' => $id, 'title' => $record->data['title'] ?? $id];
                }

                if ([] === $items) {
                    throw new \Odiseo\AiAgentBundle\Presentation\PresentationRefused('nothing to show', ProvenanceGate::NAME);
                }

                return ['items' => $items];
            },
            // Parcial: los ids ya completos que la sesión vio, sin notas ni rechazo.
            static function (array $input, $context): array {
                $items = [];
                foreach (\is_array($input['ids'] ?? null) ? $input['ids'] : [] as $id) {
                    $record = \is_scalar($id) ? $context->tools->state->seen((string) $id) : null;
                    if (null !== $record) {
                        $items[] = ['id' => (string) $id, 'title' => $record->data['title'] ?? (string) $id];
                    }
                }

                return ['items' => $items];
            },
        )];
    }

    public function execute(string $tool, array $input, ToolContext $context): ToolOutcome
    {
        $this->runs[$tool] = ($this->runs[$tool] ?? 0) + 1;

        if ('break_things' === $tool) {
            throw new \RuntimeException('the directory is down');
        }

        if ('pick_record' === $tool) {
            $id = (string) ($input['id'] ?? '');

            return ProvenanceGate::check($context->state, $id, 'call find_records')
                ?? new ToolOutcome(\sprintf('Picked %s.', $id));
        }

        if ('find_records' !== $tool) {
            return ToolOutcome::error(\sprintf('Unknown tool: %s', $tool));
        }

        if ('boom' === ($input['query'] ?? null)) {
            throw new NotServed('that area');
        }

        foreach ($this->records as $id => $title) {
            $context->state->remember(new SeenRecord($id, 'record', ['title' => $title]));
        }

        return new ToolOutcome($context->fenced(['results' => $this->records]));
    }

    public function mapError(\Throwable $error, ExecutorWording $wording): ?ToolOutcome
    {
        return $error instanceof NotServed
            ? ToolOutcome::error(\sprintf('%s is not something this organisation covers; say so plainly.', $error->getMessage()))
            : null;
    }
}
