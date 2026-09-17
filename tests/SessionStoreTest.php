<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Agent\Transcript;
use Odiseo\AiConversationalAgentBundle\Session\InMemorySessionStore;
use Odiseo\AiConversationalAgentBundle\Session\SeenRecord;
use Odiseo\AiConversationalAgentBundle\Session\SessionConflictException;
use PHPUnit\Framework\TestCase;

final class SessionStoreTest extends TestCase
{
    public function testAStartedSessionIsFoundAgainWithItsPrincipal(): void
    {
        $store = new InMemorySessionStore();
        $record = $store->start('visitor-1');

        $loaded = $store->require($record->sessionId);

        self::assertSame('visitor-1', $loaded->principalId);
        self::assertNotSame('', $record->sessionId);
    }

    public function testTheTranscriptAndTheStateSurviveARoundTrip(): void
    {
        $store = new InMemorySessionStore();
        $record = $store->start('visitor-1');

        $record->messages[] = Transcript::userMessage('hola');
        $record->state->remember(new SeenRecord('R-1', 'record', ['title' => 'Primero']));
        $store->save($record);

        $loaded = $store->require($record->sessionId);

        self::assertCount(1, $loaded->messages);
        self::assertTrue($loaded->state->hasSeen('R-1'));
        self::assertSame('Primero', $loaded->state->seen('R-1')?->data['title']);
    }

    public function testASaveThatChangedNothingWritesNothing(): void
    {
        $store = new InMemorySessionStore();
        $record = $store->start('visitor-1');
        $version = $record->version;

        $store->save($record);

        self::assertSame($version, $record->version);
    }

    public function testTheSecondWriterOfTheSameVersionIsRefused(): void
    {
        $store = new InMemorySessionStore();
        $record = $store->start('visitor-1');

        $first = $store->require($record->sessionId);
        $second = $store->require($record->sessionId);

        $first->messages[] = Transcript::userMessage('primero');
        $store->save($first);

        $second->messages[] = Transcript::userMessage('segundo');
        $this->expectException(SessionConflictException::class);
        $store->save($second);
    }

    public function testACompactedTranscriptIsRewrittenWhole(): void
    {
        $store = new InMemorySessionStore();
        $record = $store->start('visitor-1');
        $record->messages = [Transcript::userMessage('uno'), Transcript::userMessage('dos')];
        $store->save($record);

        $record->messages[0] = Transcript::userMessage('uno (recortado)');
        $record->storedMessages = 0;
        $store->save($record);

        self::assertSame('uno (recortado)', $store->require($record->sessionId)->messages[0]['content'][0]['text']);
    }
}
