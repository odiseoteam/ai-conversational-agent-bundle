<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Bridge\Symfony\Controller;

use Odiseo\AiAgentBundle\Memory\MemoryFact;
use Odiseo\AiAgentBundle\Memory\MemoryStore;
use Odiseo\AiAgentBundle\Session\SessionResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** What the agent remembers about the person and how they erase it. The subject comes from the session record, never from the request. */
final class MemoryController
{
    public function __construct(
        private readonly SessionResolver $sessions,
        private readonly MemoryStore $memory,
    ) {
    }

    public function list(Request $request): JsonResponse
    {
        $record = $this->sessions->resolve($request);

        return new JsonResponse([
            'facts' => array_map(static fn (MemoryFact $fact): array => $fact->toArray(), $this->memory->all($record->principalId)),
        ]);
    }

    public function forget(Request $request, string $key): JsonResponse
    {
        $record = $this->sessions->resolve($request);

        return new JsonResponse(['forgotten' => $this->memory->forget($record->principalId, $key)]);
    }

    public function clear(Request $request): JsonResponse
    {
        $record = $this->sessions->resolve($request);
        $this->memory->clear($record->principalId);

        return new JsonResponse(['cleared' => true]);
    }
}
