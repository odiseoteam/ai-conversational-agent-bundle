<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\MemoryFactInterface;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryFact;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryStore;

/**
 * Facts keyed by subject and key, so a later save on the same subject replaces the earlier one
 * instead of piling up near-duplicates. Search is a case-insensitive substring match per word
 * over key and value: a subject holds a handful of facts, so nothing heavier is warranted.
 */
final class OrmMemoryStore implements MemoryStore
{
    /** @param class-string<MemoryFactInterface> $factClass */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $factClass,
    ) {
    }

    public function save(string $subject, MemoryFact $fact): void
    {
        /** @var MemoryFactInterface|null $row */
        $row = $this->em->getRepository($this->factClass)->findOneBy(['subject' => $subject, 'factKey' => $fact->key]);
        if (null === $row) {
            $row = new $this->factClass();
            $row->setSubject($subject);
            $this->em->persist($row);
        }
        $row->fill($fact);

        $this->em->flush();
    }

    public function all(string $subject): array
    {
        return $this->hydrate($this->query($subject)->getQuery()->getResult());
    }

    public function search(string $subject, string $query, int $limit = 10): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: []));
        if ([] === $words) {
            return \array_slice($this->all($subject), 0, $limit);
        }

        $qb = $this->query($subject)->setMaxResults($limit);
        $or = $qb->expr()->orX();
        foreach ($words as $i => $word) {
            $or->add(\sprintf('LOWER(f.factKey) LIKE :w%1$d OR LOWER(f.factValue) LIKE :w%1$d', $i));
            $qb->setParameter('w'.$i, '%'.addcslashes($word, '%_\\').'%');
        }

        return $this->hydrate($qb->andWhere($or)->getQuery()->getResult());
    }

    public function forget(string $subject, string $key): bool
    {
        $deleted = $this->em->createQueryBuilder()
            ->delete($this->factClass, 'f')
            ->where('f.subject = :subject AND f.factKey = :key')
            ->setParameter('subject', $subject)
            ->setParameter('key', $key)
            ->getQuery()
            ->execute();

        return 0 < (int) $deleted;
    }

    public function clear(string $subject): void
    {
        $this->em->createQueryBuilder()
            ->delete($this->factClass, 'f')
            ->where('f.subject = :subject')
            ->setParameter('subject', $subject)
            ->getQuery()
            ->execute();
    }

    private function query(string $subject): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('f')
            ->from($this->factClass, 'f')
            ->where('f.subject = :subject')
            ->orderBy('f.updatedAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->setParameter('subject', $subject);
    }

    /**
     * @param list<MemoryFactInterface> $rows
     *
     * @return list<MemoryFact>
     */
    private function hydrate(array $rows): array
    {
        return array_map(static fn (MemoryFactInterface $row): MemoryFact => $row->toFact(), $rows);
    }
}
