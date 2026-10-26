<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Agent;

use Odiseo\AiConversationalAgentBundle\Agent\Transcript;
use Odiseo\AiConversationalAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\SessionRecord;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\AgentBuilder;
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
