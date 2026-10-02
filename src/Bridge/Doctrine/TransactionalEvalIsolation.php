<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Odiseo\AiConversationalAgentBundle\Eval\EvalIsolation;

/**
 * Runs a case inside a transaction on the entity manager's connection and rolls it back, then
 * clears the manager so no entity from the case is reused. What the host's tools write through
 * the same connection (a cart, an order) is undone with it. A failed flush closes the manager;
 * the registry reopens it, so the next case is not left with a closed one.
 */
final class TransactionalEvalIsolation implements EvalIsolation
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?ManagerRegistry $registry = null,
    ) {
    }

    public function isolate(\Closure $case): mixed
    {
        $connection = $this->entityManager->getConnection();
        // Only what the case opened is rolled back: a transaction around the run stays open.
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();

        try {
            return $case();
        } finally {
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            } else {
                $this->registry?->resetManager();
            }
        }
    }
}
