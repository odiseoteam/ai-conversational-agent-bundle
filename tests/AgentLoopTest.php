<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Tests;

use Odiseo\AiAgentBundle\Agent\Transcript;
use Odiseo\AiAgentBundle\Config\AgentConfig;
use Odiseo\AiAgentBundle\Execution\ChipComponent;
use Odiseo\AiAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiAgentBundle\Provider\ProviderCapabilities;
use Odiseo\AiAgentBundle\Session\SessionContext;
use Odiseo\AiAgentBundle\Session\TurnState;
use Odiseo\AiAgentBundle\Streaming\AgentEvent;
use Odiseo\AiAgentBundle\Streaming\EventType;
use Odiseo\AiAgentBundle\Tests\Fixture\AgentBuilder;
use Odiseo\AiAgentBundle\Tests\Fixture\DirectoryCapability;
use PHPUnit\Framework\TestCase;

final class AgentLoopTest extends TestCase
{
    public function testAPlainTurnStreamsTextAndCompletes(): void
    {
        $builder = new AgentBuilder(new FakeProvider([FakeProvider::text('Hola, ¿en qué te ayudo?')]));
        $messages = [Transcript::userMessage('hola')];

        $events = $this->collect($builder, $messages);

        self::assertSame('Hola, ¿en qué te ayudo?', $this->text($events));
        self::assertSame('end_turn', $this->last($events)->data['stop_reason']);
        self::assertSame('assistant', $messages[1]['role']);
    }

    public function testAToolRoundRunsTheToolAndFeedsTheResultBack(): void
    {
        $builder = new AgentBuilder(
            new FakeProvider([
                FakeProvider::toolCall('find_records', ['query' => 'algo', 'status' => 'Buscando'], 'tu-1'),
                FakeProvider::text('Encontré dos.'),
            ]),
            extra: [new DirectoryCapability()],
        );

        $messages = [Transcript::userMessage('mostrame lo que hay')];
        $state = new TurnState();
        $events = $this->collect($builder, $messages, $state);

        $call = $this->ofType($events, EventType::ToolCall)[0];
        self::assertSame('find_records', $call->data['tool']);
        self::assertSame('Buscando', $call->data['label'], 'the status line reaches the host as a label');
        self::assertArrayNotHasKey('status', $call->data['input'], 'and never reaches the tool');

        self::assertTrue($state->hasSeen('R-1'));
        self::assertSame('tool_result', $messages[2]['content'][0]['type']);
    }

    public function testAFailingToolIsReportedAsUnavailableAndDoesNotEndTheTurn(): void
    {
        $builder = new AgentBuilder(
            new FakeProvider([
                FakeProvider::toolCall('break_things', [], 'tu-1'),
                FakeProvider::text('No pude consultarlo.'),
            ]),
            extra: [new DirectoryCapability()],
        );

        $messages = [Transcript::userMessage('probá algo')];
        $events = $this->collect($builder, $messages);

        $result = $this->ofType($events, EventType::ToolResult)[0];
        self::assertTrue($result->data['is_error']);
        self::assertStringContainsString('temporarily unavailable', $messages[2]['content'][0]['content']);
        self::assertSame('No pude consultarlo.', $this->text($events));
    }

    public function testADomainErrorIsRelayedInItsOwnWords(): void
    {
        $builder = new AgentBuilder(
            new FakeProvider([
                FakeProvider::toolCall('find_records', ['query' => 'boom'], 'tu-1'),
                FakeProvider::text('Eso no lo cubrimos.'),
            ]),
            extra: [new DirectoryCapability()],
        );

        $messages = [Transcript::userMessage('algo')];
        $this->collect($builder, $messages);

        self::assertStringContainsString('not something this organisation covers', $messages[2]['content'][0]['content']);
    }

    public function testACardNamingAnUnseenRecordIsHeldByProvenance(): void
    {
        $builder = new AgentBuilder(
            new FakeProvider([
                FakeProvider::toolCall('present_records', ['ids' => ['R-9']], 'tu-1'),
                FakeProvider::text('Perdón, me confundí.'),
            ]),
            extra: [new DirectoryCapability()],
        );

        $messages = [Transcript::userMessage('mostrame R-9')];
        $events = $this->collect($builder, $messages);

        $result = $this->ofType($events, EventType::ToolResult)[0];
        self::assertSame('blocked', $result->data['status']);
        self::assertSame('provenance', $result->data['reason']);
        self::assertSame([], $this->ofType($events, EventType::Ui), 'nothing is rendered');
    }

    public function testACleanPresentationRoundWithChipsEndsTheTurn(): void
    {
        $provider = new FakeProvider([
            FakeProvider::toolCall('find_records', ['query' => 'algo'], 'tu-1'),
            new \Odiseo\AiAgentBundle\Provider\Response\ProviderResponse(
                [
                    ['type' => 'tool_use', 'id' => 'tu-2', 'name' => 'present_records', 'input' => (object) ['ids' => ['R-1']]],
                    ['type' => 'tool_use', 'id' => 'tu-3', 'name' => ChipComponent::TOOL, 'input' => (object) ['suggestions' => ['Ver el otro']]],
                ],
                [
                    new \Odiseo\AiAgentBundle\Provider\Response\ToolUse('tu-2', 'present_records', ['ids' => ['R-1']]),
                    new \Odiseo\AiAgentBundle\Provider\Response\ToolUse('tu-3', ChipComponent::TOOL, ['suggestions' => ['Ver el otro']]),
                ],
                'tool_use',
            ),
        ]);
        $builder = new AgentBuilder($provider, extra: [new DirectoryCapability()]);

        $messages = [Transcript::userMessage('mostrame')];
        $events = $this->collect($builder, $messages);

        self::assertSame('end_turn', $this->last($events)->data['stop_reason']);
        self::assertCount(2, $this->ofType($events, EventType::Ui));
        self::assertCount(2, $provider->requests(), 'the closing round is not asked for');
    }

    public function testTheFirstRoundIsPinnedToTheGroundingRead(): void
    {
        $provider = new FakeProvider([
            FakeProvider::toolCall('find_records', ['query' => '¿tienen registro?'], 'tu-1'),
            FakeProvider::text('Sí.'),
        ]);
        $builder = new AgentBuilder($provider, extra: [new DirectoryCapability()]);

        $messages = [Transcript::userMessage('¿tienen algún registro de esto?')];
        $this->collect($builder, $messages);

        $first = $provider->requests()[0];
        self::assertSame('tool', $first->toolChoice->type);
        self::assertSame('find_records', $first->toolChoice->tool);
    }

    public function testAProviderThatCannotForceGetsThePrefetchInstead(): void
    {
        $provider = new FakeProvider(
            [FakeProvider::text('Sí, tenemos dos.')],
            new ProviderCapabilities(forcedToolChoice: false),
        );
        $builder = new AgentBuilder($provider, extra: [new DirectoryCapability()]);

        $messages = [Transcript::userMessage('¿tienen algún registro de esto?')];
        $this->collect($builder, $messages);

        // The read the host did for it goes in above the visitor's message, introduced as the
        // host's own work rather than as something the visitor said.
        self::assertStringContainsString('Prefetched:', $messages[0]['content'][0]['text']);
        self::assertStringContainsString('R-1', $messages[0]['content'][0]['text']);
        self::assertStringContainsString('registro', $messages[1]['content'][0]['text']);
        self::assertSame('auto', $provider->requests()[0]->toolChoice->type);
    }

    public function testTheRollingMarkerIsSkippedOnAForcedRound(): void
    {
        $provider = new FakeProvider([
            FakeProvider::toolCall('find_records', ['query' => '¿tienen registro?'], 'tu-1'),
            FakeProvider::text('Sí.'),
        ]);
        $builder = new AgentBuilder($provider, extra: [new DirectoryCapability()]);

        $messages = [
            Transcript::userMessage('hola'),
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'buenas']]],
            Transcript::userMessage('¿tienen algún registro?'),
        ];
        $this->collect($builder, $messages);

        $forced = $provider->requests()[0];
        foreach ($forced->messages as $message) {
            foreach ($message['content'] as $block) {
                self::assertArrayNotHasKey('cache_hint', $block);
            }
        }
    }

    public function testTheTurnStopsOnTheSessionBudget(): void
    {
        $config = new AgentConfig(sessionBudgetUsd: 0.0001);
        $provider = new FakeProvider([FakeProvider::text('hola')]);
        $builder = new AgentBuilder($provider, $config);
        $builder->ledger->record('s-1', new \DateTimeImmutable(), 1.0);

        $messages = [Transcript::userMessage('hola')];
        $events = iterator_to_array($builder->loop()->streamTurn(
            $messages,
            new SessionContext('s-1', 'visitor-1', 'America/Argentina/Buenos_Aires'),
            new TurnState(),
        ), false);

        self::assertSame(EventType::Error, $events[0]->type);
        self::assertSame('budget', $this->last($events)->data['stop_reason']);
        self::assertSame([], $provider->requests(), 'the model is never called');
    }

    public function testAnAbandonedTurnLeavesNoUnansweredToolCall(): void
    {
        $builder = new AgentBuilder(
            new FakeProvider([FakeProvider::toolCall('find_records', ['query' => 'algo'], 'tu-1')]),
            extra: [new DirectoryCapability()],
        );

        $messages = [Transcript::userMessage('algo')];
        $turn = $builder->loop()->streamTurn(
            $messages,
            new SessionContext('s-1', 'visitor-1'),
            new TurnState(),
        );

        // The host walks away mid-stream, as a closed browser tab does.
        $turn->current();
        unset($turn);
        gc_collect_cycles();

        $open = [];
        foreach ($messages as $message) {
            foreach ($message['content'] as $block) {
                if ('tool_use' === ($block['type'] ?? null)) {
                    $open[$block['id']] = true;
                }
                if ('tool_result' === ($block['type'] ?? null)) {
                    unset($open[$block['tool_use_id']]);
                }
            }
        }

        self::assertSame([], $open);
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return list<AgentEvent>
     */
    private function collect(AgentBuilder $builder, array &$messages, ?TurnState $state = null): array
    {
        return iterator_to_array($builder->loop()->streamTurn(
            $messages,
            new SessionContext('s-1', 'visitor-1', 'America/Argentina/Buenos_Aires'),
            $state ?? new TurnState(),
        ), false);
    }

    public function testAStatusLineSurfacesBeforeTheRoundFinishes(): void
    {
        $provider = new Fixture\ChunkedToolCallProvider(
            tool: 'find_records',
            id: 'tu-1',
            chunks: [
                '{"status": "Buscando',
                ' en el sitio"',
                ', "query": "sylius ecommerce"}',
            ],
            finalInput: ['status' => 'Buscando en el sitio', 'query' => 'sylius ecommerce'],
        );
        $builder = new AgentBuilder($provider, extra: [new DirectoryCapability()]);

        $messages = [Transcript::userMessage('algo')];
        $events = iterator_to_array($builder->loop()->streamTurn(
            $messages,
            new SessionContext('s-1', 'visitor-1', 'America/Argentina/Buenos_Aires'),
            new TurnState(),
        ), false);

        $progress = $this->ofType($events, EventType::Progress);
        self::assertNotSame([], $progress, 'a progress event was emitted while the call was still streaming');
        self::assertSame('Buscando en el sitio', $progress[0]->data['message']);
        self::assertSame('find_records', $progress[0]->data['tool']);

        $toolCallIndex = array_search(EventType::ToolCall, array_map(static fn (AgentEvent $e): EventType => $e->type, $events), true);
        $progressIndex = array_search($progress[0], $events, true);
        self::assertLessThan($toolCallIndex, $progressIndex, 'the status line arrives before the tool_call event, which only fires once the whole round is done');
    }

    /** @param list<AgentEvent> $events */
    private function text(array $events): string
    {
        $text = '';
        foreach ($this->ofType($events, EventType::TextDelta) as $event) {
            $text .= $event->data['text'];
        }

        return $text;
    }

    /**
     * @param list<AgentEvent> $events
     *
     * @return list<AgentEvent>
     */
    private function ofType(array $events, EventType $type): array
    {
        return array_values(array_filter($events, static fn (AgentEvent $event): bool => $event->type === $type));
    }

    /** @param list<AgentEvent> $events */
    private function last(array $events): AgentEvent
    {
        return $events[\count($events) - 1];
    }
}
