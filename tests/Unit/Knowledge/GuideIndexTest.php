<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Knowledge;

use Odiseo\AiConversationalAgentBundle\Knowledge\GuideIndex;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeDocument;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeKind;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeSourceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class GuideIndexTest extends TestCase
{
    public function testOnlyGuidesAreListedByTitleWhateverTheSourceOrder(): void
    {
        $index = new GuideIndex([
            $this->source($this->guide('plans', 'Plans'), new KnowledgeDocument('refunds', 'Refunds', 'Body.')),
            $this->source($this->guide('classes', 'Classes'), new KnowledgeDocument('tip', 'Tip', 'Body.', KnowledgeKind::Snippet)),
        ]);

        self::assertSame(['classes', 'plans'], $this->ids($index));
    }

    public function testARepeatedIdALongWhenAndGuidesPastTheCapAreLeftOutAndLogged(): void
    {
        $logger = $this->logger();
        $index = new GuideIndex([$this->source(
            $this->guide('a', 'A'),
            $this->guide('a', 'A again'),
            $this->guide('long', 'Long', str_repeat('x', 11)),
            $this->guide('b', 'B'),
            $this->guide('c', 'C'),
        )], maxGuides: 2, maxWhenChars: 10, logger: $logger);

        self::assertSame(['a', 'b'], $this->ids($index));
        self::assertSame(['a: its id is already used', 'long: its "when" is longer than 10 characters', 'c: only 2 guides are listed'], $logger->skipped);
    }

    public function testAFailingSourceIsLeftOut(): void
    {
        $failing = new class implements KnowledgeSourceInterface {
            public function documents(): iterable
            {
                throw new \RuntimeException('Database down');
            }
        };

        self::assertSame(['plans'], $this->ids(new GuideIndex([$failing, $this->source($this->guide('plans', 'Plans'))])));
    }

    private function guide(string $id, string $title, string $when = 'To choose.'): KnowledgeDocument
    {
        return new KnowledgeDocument($id, $title, 'Body.', KnowledgeKind::Guide, $when);
    }

    private function source(KnowledgeDocument ...$documents): KnowledgeSourceInterface
    {
        return new class(array_values($documents)) implements KnowledgeSourceInterface {
            /** @param list<KnowledgeDocument> $documents */
            public function __construct(private readonly array $documents)
            {
            }

            public function documents(): iterable
            {
                return $this->documents;
            }
        };
    }

    /** @return list<string> */
    private function ids(GuideIndex $index): array
    {
        return array_map(static fn (KnowledgeDocument $guide): string => $guide->id, $index->guides());
    }

    /** @return AbstractLogger&object{skipped: list<string>} */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $skipped = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $id = $context['id'] ?? '';
                $reason = $context['reason'] ?? '';
                $this->skipped[] = (\is_string($id) ? $id : '').': '.(\is_string($reason) ? $reason : '');
            }
        };
    }
}
