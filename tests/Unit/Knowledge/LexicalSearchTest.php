<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Knowledge;

use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeDocument;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeHit;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeSourceInterface;
use Odiseo\AiConversationalAgentBundle\Knowledge\LexicalSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class LexicalSearchTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function otherForms(): iterable
    {
        yield 'without accents' => ['cafe', 'hours'];
        yield 'another form of the word' => ['refunded', 'refunds'];
        yield 'a plural' => ['payments', 'payments'];
        yield 'upper case' => ['INVOICE', 'invoices'];
    }

    #[DataProvider('otherForms')]
    public function testAnotherFormOfTheWordFindsTheDocument(string $query, string $expected): void
    {
        $hits = $this->search($this->documents())->search($query, 1);

        self::assertSame($expected, $hits[0]->document->id ?? null);
    }

    public function testACommonWordWeighsLessThanARareOne(): void
    {
        $search = $this->search([
            new KnowledgeDocument('a', 'Opening hours', "The café opens at eight.\nThe café closes at six."),
            new KnowledgeDocument('b', 'Refunds', 'A refund for the café pass takes a week.'),
            new KnowledgeDocument('c', 'Guests', 'A guest can visit the café.'),
        ]);

        self::assertSame(['b'], $this->ids($search->search('refund café', 1)));
    }

    public function testTheTitleWeighsMoreThanTheBody(): void
    {
        $search = $this->search([
            new KnowledgeDocument('body', 'Payments', 'We also refund by card.'),
            new KnowledgeDocument('title', 'Refunds', 'Within 14 days.'),
        ]);

        self::assertSame(['title', 'body'], $this->ids($search->search('refunds', 2)));
    }

    public function testTheSourcesAreRankedTogether(): void
    {
        $search = new LexicalSearch([
            $this->source(new KnowledgeDocument('files', 'Refunds', 'A refund takes a week.')),
            $this->source(new KnowledgeDocument('cms', 'Terms', str_repeat('Legal text. ', 30).'No refund after a year.')),
        ], minScoreRatio: 0.0);

        self::assertSame(['files', 'cms'], $this->ids($search->search('refund', 2)));
    }

    public function testAHitFarBelowTheBestOneIsLeftOut(): void
    {
        $search = $this->search([
            new KnowledgeDocument('refunds', 'Refunds', "## Can I cancel my membership for a refund?\n\nYes, cancel within 14 days for a full refund."),
            new KnowledgeDocument('guests', 'Guests', 'A guest pays for the day.'),
        ]);

        self::assertSame(['refunds'], $this->ids($search->search('cancel my membership for a refund, and a guest?', 3)));
    }

    public function testAHitCloseToTheBestOneIsKept(): void
    {
        $search = $this->search([
            new KnowledgeDocument('refunds', 'Refunds', 'A refund takes a week.'),
            new KnowledgeDocument('cancellations', 'Cancellations', 'A cancelled membership gets a refund.'),
        ]);

        self::assertSame(['refunds', 'cancellations'], $this->ids($search->search('refund', 3)));
    }

    public function testWithoutAFloorEveryMatchIsKept(): void
    {
        $search = new LexicalSearch([$this->source(
            new KnowledgeDocument('refunds', 'Refunds', "## Can I cancel my membership for a refund?\n\nYes, cancel within 14 days for a full refund."),
            new KnowledgeDocument('guests', 'Guests', 'A guest pays for the day.'),
        )], minScoreRatio: 0.0);

        self::assertSame(['refunds', 'guests'], $this->ids($search->search('cancel my membership for a refund, and a guest?', 3)));
    }

    public function testASectionOfHeadingsOnlyIsLeftOut(): void
    {
        $search = $this->search([new KnowledgeDocument('hours', 'Opening hours', "# Opening hours\n\n## When are you open?\n\nEvery weekday.")]);

        self::assertSame(['When are you open?'], array_map(static fn (KnowledgeHit $hit): ?string => $hit->section, $search->search('open', 3)));
    }

    public function testALongSectionComesBackAsAWindowAroundItsFirstMatch(): void
    {
        $body = str_repeat('Filler text. ', 40).'The refund takes a week. '.str_repeat('More filler. ', 40);
        $search = new LexicalSearch([$this->source(new KnowledgeDocument('long', 'Terms', $body))], maxSectionChars: 300);

        $content = $search->search('refund', 1)[0]->content;
        self::assertStringStartsWith('…', $content);
        self::assertStringEndsWith('…', $content);
        self::assertStringContainsString('The refund takes a week.', $content);
        self::assertSame(302, mb_strlen($content));
    }

    public function testNoUsefulWordFindsNothing(): void
    {
        self::assertSame([], $this->search($this->documents())->search('a I o', 3));
        self::assertSame([], $this->search($this->documents())->search('telephone', 3));
    }

    public function testAFailingSourceIsLeftOutAndLogged(): void
    {
        $failing = new class implements KnowledgeSourceInterface {
            public function documents(): iterable
            {
                throw new \RuntimeException('Database down');
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

        $search = new LexicalSearch([$failing, $this->source(...$this->documents())], logger: $logger);

        self::assertSame(['hours'], $this->ids($search->search('café', 1)));
        self::assertSame(['Database down'], $logger->messages);
    }

    /** @param list<KnowledgeDocument> $documents */
    private function search(array $documents): LexicalSearch
    {
        return new LexicalSearch([$this->source(...$documents)]);
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

    /** @return list<KnowledgeDocument> */
    private function documents(): array
    {
        return [
            new KnowledgeDocument('hours', 'Opening hours', "## When is the café open?\n\nFrom 8 to 18 on weekdays."),
            new KnowledgeDocument('refunds', 'Refunds', "## Can I get a refund?\n\nYes, within 14 days."),
            new KnowledgeDocument('payments', 'Payment methods', "## How can I pay?\n\nBy card or bank transfer."),
            new KnowledgeDocument('invoices', 'Invoices', "## Do you send an invoice?\n\nYes, by email."),
        ];
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
