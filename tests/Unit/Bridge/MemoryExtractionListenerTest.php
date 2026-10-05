<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Bridge;

use Odiseo\AiConversationalAgentBundle\Agent\Transcript;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\EventListener\MemoryExtractionListener;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Messenger\ExtractMemoryHandler;
use Odiseo\AiConversationalAgentBundle\Memory\ExtractMemory;
use Odiseo\AiConversationalAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderException;
use Odiseo\AiConversationalAgentBundle\Session\SessionContext;
use Odiseo\AiConversationalAgentBundle\Session\SessionRecord;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\AgentBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MemoryExtractionListenerTest extends TestCase
{
    public function testTheTurnItselfNoLongerExtracts(): void
    {
        [$builder, $memory] = $this->builder();
        $record = new SessionRecord('s-1', 'visitor-1', new TurnState());

        iterator_to_array($builder->turnRunner()->run($record, new SessionContext('s-1', 'visitor-1'), 'I wear an M'), false);

        self::assertCount(0, $memory->requests());
    }

    public function testWithoutABusItExtractsOnceTheResponseIsOut(): void
    {
        [$builder, $memory] = $this->builder();
        $listener = new MemoryExtractionListener($builder->turnRunner(), new NullLogger());

        $listener->defer($this->turn());
        self::assertCount(0, $memory->requests(), 'nothing runs while the response streams');

        $listener->onTerminate($this->terminate());
        $listener->onTerminate($this->terminate());

        self::assertCount(1, $memory->requests(), 'once, and not again on the next request');
    }

    public function testWithABusTheRoutingDecidesWhereItRuns(): void
    {
        [$builder, $memory] = $this->builder();
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message, $stamps);
            }
        };
        $listener = new MemoryExtractionListener($builder->turnRunner(), new NullLogger(), $bus);

        $listener->defer($turn = $this->turn());
        $listener->onTerminate($this->terminate());

        self::assertSame([$turn], $bus->dispatched);
        self::assertCount(0, $memory->requests());

        (new ExtractMemoryHandler($builder->loop()))($turn);

        self::assertCount(1, $memory->requests());
    }

    public function testAFailureReachesTheHandlerAndIsSwallowedInPlace(): void
    {
        $builder = new AgentBuilder(new FakeProvider([FakeProvider::text('Noted.')]));
        $builder->memoryProvider = new FakeProvider([]);

        // In place the response is out and nobody is left to tell.
        $builder->turnRunner()->remember($this->turn());

        // On a transport the failure is what makes it retry.
        $this->expectException(ProviderException::class);
        (new ExtractMemoryHandler($builder->loop()))($this->turn());
    }

    public function testTheMessageCarriesOnlyTheLatestExchange(): void
    {
        [$builder] = $this->builder();
        $messages = [
            Transcript::userMessage('I wear an M'),
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Noted.']]],
            Transcript::userMessage('And my son is 6'),
        ];

        $turn = $builder->loop()->memoryOf($messages, new SessionContext('s-1', 'visitor-1'));

        self::assertSame('visitor-1', $turn->principalId);
        self::assertSame('s-1', $turn->sessionId);
        self::assertStringContainsString('my son is 6', $turn->exchange);
        self::assertStringNotContainsString('I wear an M', $turn->exchange);
    }

    /** @return array{0: AgentBuilder, 1: FakeProvider} */
    private function builder(): array
    {
        $builder = new AgentBuilder(new FakeProvider([FakeProvider::text('Noted.')]));
        $builder->memoryProvider = $memory = new FakeProvider([FakeProvider::text('[]')]);

        return [$builder, $memory];
    }

    private function turn(): ExtractMemory
    {
        return new ExtractMemory('visitor-1', 's-1', 'user: I wear an M');
    }

    private function terminate(): TerminateEvent
    {
        return new TerminateEvent($this->createStub(HttpKernelInterface::class), new Request(), new Response());
    }
}
