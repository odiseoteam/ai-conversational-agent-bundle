<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Agent;

use Odiseo\AiConversationalAgentBundle\Agent\Transcript;
use Odiseo\AiConversationalAgentBundle\Agent\TurnRunner;
use Odiseo\AiConversationalAgentBundle\Host\TurnHookInterface;
use Odiseo\AiConversationalAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\SessionRecord;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\AgentBuilder;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\CountingTurnHook;
use PHPUnit\Framework\TestCase;

final class TurnRunnerTest extends TestCase
{
    public function testARetryAnswersTheUnansweredMessageWithoutStoringItTwice(): void
    {
        $record = $this->unanswered();

        $this->runTurn($record, 'cheaper ones', retry: true);

        self::assertSame(['user', 'assistant'], array_column($record->messages, 'role'));
        self::assertSame('cheaper ones', Transcript::latestUserText($record->messages));
    }

    public function testTheSameMessageSentAgainWithoutRetryIsStored(): void
    {
        $record = $this->unanswered();

        $this->runTurn($record, 'cheaper ones');

        self::assertSame(['user', 'user', 'assistant'], array_column($record->messages, 'role'));
    }

    public function testARetryOfAMessageThatNeverArrivedStoresIt(): void
    {
        $record = new SessionRecord('s-1', 'visitor-1', new TurnState(), messages: [
            Transcript::userMessage('hi'),
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello.']]],
        ]);

        $this->runTurn($record, 'cheaper ones', retry: true);

        self::assertSame(['user', 'assistant', 'user', 'assistant'], array_column($record->messages, 'role'));
    }

    public function testTheHookRunsOnceWhenTheTurnIsNotPrepared(): void
    {
        $hook = new CountingTurnHook();
        $runner = $this->runnerWith($hook);
        $session = new SessionContext('s-1', 'visitor-1');

        $this->drain($runner, $session);

        self::assertSame(1, $hook->calls);
    }

    /** A streaming surface prepares before its response starts; the turn then skips the hook. */
    public function testAPreparedTurnDoesNotRunTheHookAgain(): void
    {
        $hook = new CountingTurnHook();
        $runner = $this->runnerWith($hook);
        $session = new SessionContext('s-1', 'visitor-1');

        $runner->prepare($session);
        self::assertSame(1, $hook->calls, 'the hook ran before the turn streams');

        $this->drain($runner, $session);
        self::assertSame(1, $hook->calls);
    }

    public function testThePreparationCoversOneTurnOnly(): void
    {
        $hook = new CountingTurnHook();
        $runner = $this->runnerWith($hook, 2);
        $session = new SessionContext('s-1', 'visitor-1');

        $runner->prepare($session);
        $this->drain($runner, $session);
        $this->drain($runner, $session);

        self::assertSame(2, $hook->calls);
    }

    private function runnerWith(TurnHookInterface $hook, int $turns = 1): TurnRunner
    {
        $responses = array_fill(0, $turns, FakeProvider::text('Hello.'));
        $loop = (new AgentBuilder(new FakeProvider($responses)))->loop();

        return new TurnRunner($loop, $hook, 'What happened meanwhile');
    }

    private function drain(TurnRunner $runner, SessionContext $session): void
    {
        iterator_to_array($runner->run(new SessionRecord('s-1', 'visitor-1', new TurnState()), $session, 'hi'), false);
    }

    private function unanswered(): SessionRecord
    {
        return new SessionRecord('s-1', 'visitor-1', new TurnState(), messages: [Transcript::userMessage('cheaper ones')]);
    }

    private function runTurn(SessionRecord $record, string $message, bool $retry = false): void
    {
        $runner = (new AgentBuilder(new FakeProvider([FakeProvider::text('Here they are.')])))->turnRunner();
        iterator_to_array($runner->run($record, new SessionContext('s-1', 'visitor-1'), $message, $retry), false);
    }
}
