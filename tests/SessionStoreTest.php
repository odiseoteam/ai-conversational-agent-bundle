<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Agent\Transcript;
use Odiseo\AiConversationalAgentBundle\Session\InMemorySessionStore;
use Odiseo\AiConversationalAgentBundle\Session\SeenRecord;
use Odiseo\AiConversationalAgentBundle\Session\SessionConflictException;
use Odiseo\AiConversationalAgentBundle\Session\SessionStore;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Orm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The contract every session store keeps, run against the in-memory one and the ORM one. */
final class SessionStoreTest extends TestCase
{
    /** @return iterable<string, array{0: \Closure(): SessionStore}> */
    public static function stores(): iterable
    {
        yield 'memory' => [static fn (): SessionStore => new InMemorySessionStore()];
        yield 'orm' => [static fn (): SessionStore => Orm::sessionStore()];
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function testAStartedSessionIsFoundAgainWithItsPrincipal(\Closure $make): void
    {
        $store = $make();
        $record = $store->start('visitor-1');

        $loaded = $store->require($record->sessionId);

        self::assertSame('visitor-1', $loaded->principalId);
        self::assertNotSame('', $record->sessionId);
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function testTheTranscriptAndTheStateSurviveARoundTrip(\Closure $make): void
    {
        $store = $make();
        $record = $store->start('visitor-1');

        $record->messages[] = Transcript::userMessage('hola');
        $record->state->remember(new SeenRecord('R-1', 'record', ['title' => 'Primero']));
        $store->save($record);

        $loaded = $store->require($record->sessionId);

        self::assertCount(1, $loaded->messages);
        self::assertTrue($loaded->state->hasSeen('R-1'));
        self::assertSame('Primero', $loaded->state->seen('R-1')?->data['title']);
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function testASaveThatChangedNothingWritesNothing(\Closure $make): void
    {
        $store = $make();
        $record = $store->start('visitor-1');
        $version = $record->version;

        $store->save($record);

        self::assertSame($version, $record->version);
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function testTheSecondWriterOfTheSameVersionIsRefused(\Closure $make): void
    {
        $store = $make();
        $record = $store->start('visitor-1');

        $first = $store->require($record->sessionId);
        $second = $store->require($record->sessionId);

        $first->messages[] = Transcript::userMessage('primero');
        $store->save($first);

        $second->messages[] = Transcript::userMessage('segundo');
        $this->expectException(SessionConflictException::class);
        $store->save($second);
    }

    /** @param \Closure(): SessionStore $make */
    #[DataProvider('stores')]
    public function testACompactedTranscriptIsRewrittenWhole(\Closure $make): void
    {
        $store = $make();
        $record = $store->start('visitor-1');
        $record->messages = [Transcript::userMessage('uno'), Transcript::userMessage('dos')];
        $store->save($record);

        $record->messages[0] = Transcript::userMessage('uno (recortado)');
        $record->storedMessages = 0;
        $store->save($record);

        self::assertSame('uno (recortado)', $store->require($record->sessionId)->messages[0]['content'][0]['text']);
    }
}
