<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

use Odiseo\AiConversationalAgentBundle\Support\Scalar;

/**
 * One behavioural case. `state` is the precondition the runner loads before the turn; `turns`
 * is one message unless carrying state across turns is the behaviour under test; `expected`
 * holds only the keys the case is about.
 *
 * Gate behaviour that needs no model — provenance, caps, the write filter — is unit-tested
 * with the fake provider instead. These cases are for what the model decides.
 */
final readonly class EvalCase
{
    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $state
     * @param list<string>         $turns
     * @param array<string, mixed> $expected
     */
    public function __construct(
        public string $id,
        public array $turns,
        public array $expected,
        public string $priority = 'medium',
        public string $difficulty = 'medium',
        public array $tags = [],
        public array $state = [],
        public ?string $skip = null,
        public string $notes = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            Scalar::string($row['id'] ?? null),
            Scalar::strings($row['turns'] ?? null),
            Scalar::keyed($row['expected'] ?? null),
            Scalar::string($row['priority'] ?? null, 'medium'),
            Scalar::string($row['difficulty'] ?? null, 'medium'),
            Scalar::strings($row['tags'] ?? null),
            Scalar::keyed($row['state'] ?? null),
            Scalar::nullableString($row['skip'] ?? null),
            Scalar::string($row['notes'] ?? null),
        );
    }
}
