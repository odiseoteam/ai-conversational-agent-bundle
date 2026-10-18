<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store;

use Doctrine\ORM\EntityManagerInterface;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\ContentVersionInterface;
use Odiseo\AiConversationalAgentBundle\Content\Version;
use Odiseo\AiConversationalAgentBundle\Content\VersionState;
use Odiseo\AiConversationalAgentBundle\Content\VersionStoreInterface;

/**
 * Versions as rows that are only added; the draft is the row numbered 0. The revision is the
 * newest published row's id, read again after a short while, so every process (the web, the
 * chat, a worker) sees a publication within that time and this one sees its own at once.
 */
final class OrmVersionStore implements VersionStoreInterface
{
    private const REVISION_SECONDS = 2.0;

    private ?string $revision = null;

    private float $revisionReadAt = 0.0;

    /** @var array<string, array{string, array<string, Version>}> published versions by kind, with the revision they were read at */
    private array $published = [];

    /** @param class-string<ContentVersionInterface> $versionClass */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $versionClass,
    ) {
    }

    public function revision(): string
    {
        $now = microtime(true);
        if (null === $this->revision || $now - $this->revisionReadAt > self::REVISION_SECONDS) {
            // MAX() first and bare: a DQL walker that appends ORDER BY skips aggregate selects.
            $max = $this->em->createQueryBuilder()
                ->select('MAX(v.id)')
                ->from($this->versionClass, 'v')
                ->where('v.number > 0')
                ->getQuery()
                ->getSingleScalarResult();
            $this->revision = (string) (\is_scalar($max) ? $max : '0');
            $this->revisionReadAt = $now;
        }

        return $this->revision;
    }

    public function published(string $kind): array
    {
        $revision = $this->revision();
        if (($this->published[$kind][0] ?? null) === $revision) {
            return $this->published[$kind][1];
        }

        /** @var list<ContentVersionInterface> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('v')
            ->from($this->versionClass, 'v')
            ->where('v.kind = :kind AND v.number > 0')
            ->setParameter('kind', $kind)
            ->orderBy('v.itemKey', 'ASC')
            ->addOrderBy('v.number', 'DESC')
            ->getQuery()
            ->getResult();

        $newest = [];
        foreach ($rows as $row) {
            $newest[$row->getItemKey()] ??= $this->version($row);
        }
        ksort($newest, \SORT_STRING);
        $this->published[$kind] = [$revision, $newest];

        return $newest;
    }

    public function history(string $kind, string $key): array
    {
        /** @var list<ContentVersionInterface> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('v')
            ->from($this->versionClass, 'v')
            ->where('v.kind = :kind AND v.itemKey = :key AND v.number > 0')
            ->setParameter('kind', $kind)
            ->setParameter('key', $key)
            ->orderBy('v.number', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map($this->version(...), $rows);
    }

    public function draft(string $kind, string $key): ?Version
    {
        $row = $this->draftRow($kind, $key);

        return null === $row ? null : $this->version($row);
    }

    public function drafts(string $kind): array
    {
        /** @var list<ContentVersionInterface> $rows */
        $rows = $this->em->getRepository($this->versionClass)->findBy(['kind' => $kind, 'number' => 0]);

        $drafts = [];
        foreach ($rows as $row) {
            $drafts[$row->getItemKey()] = $this->version($row);
        }
        ksort($drafts, \SORT_STRING);

        return $drafts;
    }

    public function saveDraft(string $kind, string $key, array $data, ?string $author = null): Version
    {
        $row = $this->draftRow($kind, $key);
        if (null === $row) {
            $row = $this->row($kind, $key, 0);
            $this->em->persist($row);
        }
        $row->setData($data);
        $row->setAuthor($author);
        $row->setCreatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->version($row);
    }

    public function discardDraft(string $kind, string $key): void
    {
        $row = $this->draftRow($kind, $key);
        if (null !== $row) {
            $this->em->remove($row);
            $this->em->flush();
        }
    }

    public function publish(string $kind, string $key, ?array $data, ?string $author = null, ?string $note = null, bool $hasDefault = false): Version
    {
        $last = $this->em->createQueryBuilder()
            ->select('MAX(v.number)')
            ->from($this->versionClass, 'v')
            ->where('v.kind = :kind AND v.itemKey = :key')
            ->setParameter('kind', $kind)
            ->setParameter('key', $key)
            ->getQuery()
            ->getSingleScalarResult();
        $number = max(is_numeric($last) ? (int) $last : 0, $hasDefault ? 1 : 0) + 1;

        $row = $this->row($kind, $key, $number);
        $row->setData($data);
        $row->setAuthor($author);
        $row->setNote(null === $note || '' === trim($note) ? null : trim($note));
        $this->em->persist($row);

        $draft = $this->draftRow($kind, $key);
        if (null !== $draft) {
            $this->em->remove($draft);
        }
        $this->em->flush();

        $this->revision = null;
        $this->published = [];

        return $this->version($row);
    }

    private function draftRow(string $kind, string $key): ?ContentVersionInterface
    {
        /** @var ContentVersionInterface|null */
        return $this->em->getRepository($this->versionClass)->findOneBy(['kind' => $kind, 'itemKey' => $key, 'number' => 0]);
    }

    private function row(string $kind, string $key, int $number): ContentVersionInterface
    {
        $row = new $this->versionClass();
        $row->setKind($kind);
        $row->setItemKey($key);
        $row->setNumber($number);

        return $row;
    }

    private function version(ContentVersionInterface $row): Version
    {
        return new Version(
            $row->getKind(),
            $row->getItemKey(),
            $row->getNumber(),
            0 === $row->getNumber() ? VersionState::Draft : VersionState::Published,
            $row->getData(),
            $row->getAuthor(),
            $row->getNote(),
            $row->getCreatedAt(),
        );
    }
}
