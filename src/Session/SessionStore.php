<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Session;

/**
 * The session record's home. A deployment subclasses this and implements the six storage
 * methods over its own store; everything above them stays.
 *
 * start() is the only place a principal enters the store. Every later request carries the
 * session id alone and the routes read the principal from the record, so no request shape
 * names a user. A deployment authenticates the caller before start() and passes the
 * principal it verified.
 */
abstract class SessionStore
{
    public function start(string $principalId): SessionRecord
    {
        $record = new SessionRecord(
            sessionId: bin2hex(random_bytes(18)),
            principalId: $principalId,
            state: new TurnState(),
        );
        $this->save($record);

        return $record;
    }

    public function require(string $sessionId): SessionRecord
    {
        $stored = $this->readState($sessionId);
        if (null === $stored) {
            throw new UnknownSessionException($sessionId);
        }

        [$version, $document] = $stored;
        $messages = $this->readMessages($sessionId);

        return new SessionRecord(
            sessionId: $sessionId,
            principalId: (string) ($document['principal_id'] ?? ''),
            state: TurnState::fromArray(\is_array($document['state'] ?? null) ? $document['state'] : []),
            messages: $messages,
            pendingAppEvents: array_values(array_map(
                'strval',
                \is_array($document['pending_app_events'] ?? null) ? $document['pending_app_events'] : [],
            )),
            version: $version,
            storedState: $document,
            storedMessages: \count($messages),
        );
    }

    /**
     * The state document first, under the version check, whenever it changed or the transcript
     * grew, so a request that lost a race writes nothing at all; then the messages the store
     * lacks.
     */
    public function save(SessionRecord $record): void
    {
        if ($record->ended) {
            return;
        }

        $document = $record->stateDocument();
        $grew = $record->storedMessages < \count($record->messages);

        if ($document !== $record->storedState || $grew) {
            $this->writeState($record->sessionId, $document, $record->version);
            ++$record->version;
            $record->storedState = $document;
        }

        if ($grew) {
            $new = \array_slice($record->messages, $record->storedMessages);
            $this->writeMessages($record->sessionId, $new, $record->storedMessages);
            $record->storedMessages = \count($record->messages);
        }
    }

    public function reset(SessionRecord $record): void
    {
        $record->ended = true;
        $this->delete($record->sessionId);
    }

    /** @return array{0: int, 1: array<string, mixed>}|null */
    abstract public function readState(string $sessionId): ?array;

    /**
     * Store $document as $version + 1 if the stored version is still $version (0 while a
     * session is being started): a compare-and-set in a shared store.
     *
     * @param array<string, mixed> $document
     *
     * @throws SessionConflictException
     */
    abstract public function writeState(string $sessionId, array $document, int $version): void;

    /** @return list<array<string, mixed>> */
    abstract public function readMessages(string $sessionId): array;

    /**
     * Replace the transcript from $start on: an append when $start is its stored length, the
     * whole transcript after a turn compacted it.
     *
     * @param list<array<string, mixed>> $messages
     */
    abstract public function writeMessages(string $sessionId, array $messages, int $start): void;

    abstract public function delete(string $sessionId): void;

    /** @return list<string> */
    abstract public function sessionIdsForPrincipal(string $principalId): array;
}
