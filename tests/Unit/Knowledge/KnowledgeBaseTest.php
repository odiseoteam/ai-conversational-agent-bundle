<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Knowledge;

use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeBase;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeDocument;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeHit;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeSearchInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class KnowledgeBaseTest extends TestCase
{
    public function testOneSearchKeepsItsOrder(): void
    {
        $base = new KnowledgeBase([$this->search('a', 'b', 'c')]);

        self::assertSame(['a', 'b'], $this->ids($base->search('x', 2)));
    }

    public function testSearchesAreMergedByPositionNotByScore(): void
    {
        $base = new KnowledgeBase([$this->search('files-1', 'files-2'), $this->search('cms-1', 'cms-2')]);

        self::assertSame(['files-1', 'cms-1', 'files-2', 'cms-2'], $this->ids($base->search('x', 4)));
    }

    public function testAHitTwoSearchesAgreeOnGoesFirst(): void
    {
        $base = new KnowledgeBase([$this->search('a', 'shared'), $this->search('b', 'shared')]);

        self::assertSame(['shared', 'a', 'b'], $this->ids($base->search('x', 3)));
    }

    public function testAFailingSearchIsLeftOutAndLogged(): void
    {
        $failing = new class implements KnowledgeSearchInterface {
            public function search(string $query, int $limit): array
            {
                throw new \RuntimeException('Search engine down');
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = \is_string($context['message'] ?? null) ? $context['message'] : '';
            }
        };

        $base = new KnowledgeBase([$failing, $this->search('a')], $logger);

        self::assertSame(['a'], $this->ids($base->search('x', 3)));
        self::assertSame(['Search engine down'], $logger->messages);
    }

    private function search(string ...$ids): KnowledgeSearchInterface
    {
        $hits = array_map(static fn (string $id): KnowledgeHit => new KnowledgeHit(new KnowledgeDocument($id, $id, 'Body.'), 'Body.'), array_values($ids));

        return new class($hits) implements KnowledgeSearchInterface {
            /** @param list<KnowledgeHit> $hits */
            public function __construct(private readonly array $hits)
            {
            }

            public function search(string $query, int $limit): array
            {
                return \array_slice($this->hits, 0, $limit);
            }
        };
    }

    /**
     * @param list<KnowledgeHit> $hits
     *
     * @return list<string>
     */
    private function ids(array $hits): array
    {
        return array_map(static fn (KnowledgeHit $hit): string => $hit->document->id, $hits);
    }
}
