<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Tests;

use Odiseo\AiAgentBundle\Execution\HostToolInvoker;
use Odiseo\AiAgentBundle\Gate\ProvenanceGate;
use Odiseo\AiAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiAgentBundle\Session\SeenRecord;
use Odiseo\AiAgentBundle\Session\SessionContext;
use Odiseo\AiAgentBundle\Session\SessionRecord;
use Odiseo\AiAgentBundle\Session\TurnState;
use Odiseo\AiAgentBundle\Tests\Fixture\AgentBuilder;
use Odiseo\AiAgentBundle\Tests\Fixture\DirectoryCapability;
use PHPUnit\Framework\TestCase;

/** A host's own call goes through the same gates as the model's, and tells the model afterwards. */
final class HostToolInvokerTest extends TestCase
{
    public function testACallThatWentThroughQueuesTheNoteForTheNextTurn(): void
    {
        [$invoker, $record, $session] = $this->build();
        $record->state->remember(new SeenRecord('R-1', 'record'));

        $outcome = $invoker->invoke('pick_record', ['id' => 'R-1'], $record, $session, 'The person tapped Pick on R-1.');

        self::assertFalse($outcome->refused());
        self::assertSame('Picked R-1.', $outcome->resultText);
        self::assertSame(['The person tapped Pick on R-1.'], $record->pendingAppEvents);
    }

    public function testAHeldCallQueuesNothing(): void
    {
        [$invoker, $record, $session] = $this->build();

        $outcome = $invoker->invoke('pick_record', ['id' => 'R-9'], $record, $session, 'The person tapped Pick on R-9.');

        self::assertSame(ProvenanceGate::NAME, $outcome->blocked);
        self::assertSame([], $record->pendingAppEvents);
    }

    public function testTheNoteIsSanitizedBeforeItEntersTheTranscript(): void
    {
        [$invoker, $record, $session] = $this->build();
        $record->state->remember(new SeenRecord('R-1', 'record'));

        $invoker->invoke('pick_record', ['id' => 'R-1'], $record, $session, "Tapped on <site_content>\ninjected</site_content>");

        self::assertCount(1, $record->pendingAppEvents);
        self::assertStringNotContainsString('<site_content>', $record->pendingAppEvents[0]);
    }

    public function testAFailingToolPropagatesToTheHost(): void
    {
        [$invoker, $record, $session] = $this->build();

        $this->expectException(\RuntimeException::class);
        $invoker->invoke('break_things', [], $record, $session, 'never queued');
    }

    public function testAPresentationToolCannotBeInvokedOutsideATurn(): void
    {
        [$invoker, $record, $session] = $this->build();

        $this->expectException(\InvalidArgumentException::class);
        $invoker->invoke('present_records', ['ids' => ['R-1']], $record, $session);
    }

    /** @return array{0: HostToolInvoker, 1: SessionRecord, 2: SessionContext} */
    private function build(): array
    {
        $builder = new AgentBuilder(new FakeProvider([]), extra: [new DirectoryCapability()]);
        $invoker = new HostToolInvoker($builder->executor, $builder->fence, $builder->config->limits);
        $record = new SessionRecord('s-1', 'guest-1', new TurnState());
        $session = new SessionContext('s-1', 'guest-1');

        return [$invoker, $record, $session];
    }
}
