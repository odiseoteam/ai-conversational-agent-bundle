<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Knowledge;

use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeDocument;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeKind;
use Odiseo\AiConversationalAgentBundle\Knowledge\LexicalSearch;
use Odiseo\AiConversationalAgentBundle\Knowledge\MarkdownKnowledgeSource;
use PHPUnit\Framework\TestCase;

final class MarkdownKnowledgeSourceTest extends TestCase
{
    private const DIR = __DIR__.'/Resources/knowledge';

    public function testTheFrontMatterDescribesTheDocument(): void
    {
        $refunds = $this->documents()['refunds'];

        self::assertSame('Refunds and cancellations', $refunds->title);
        self::assertSame(KnowledgeKind::Policy, $refunds->kind);
        self::assertSame('Refunds and cancelling a membership.', $refunds->when);
        self::assertSame('/refunds', $refunds->url);
        self::assertStringStartsWith('# Refunds and cancellations', $refunds->body);
        self::assertSame(KnowledgeKind::Guide, $this->documents()['membership-guide']->kind);
    }

    public function testWithoutFrontMatterTheFileNameIsTheIdAndTheFirstHeadingTheTitle(): void
    {
        $hours = $this->documents()['hours'];

        self::assertSame('Opening hours', $hours->title);
        self::assertSame(KnowledgeKind::Policy, $hours->kind);
        self::assertNull($hours->url);
    }

    public function testADisabledDocumentIsLeftOut(): void
    {
        self::assertArrayNotHasKey('draft', $this->documents());
    }

    public function testAMissingDirectoryHasNoDocuments(): void
    {
        self::assertSame([], [...(new MarkdownKnowledgeSource(__DIR__.'/Resources/no-such-dir'))->documents()]);
    }

    public function testASectionComesBackWholeWithItsDeeperHeadings(): void
    {
        $search = new LexicalSearch([new MarkdownKnowledgeSource(self::DIR)]);

        $cancel = $search->search('cancel my membership', 1)[0];
        self::assertSame('Can I cancel my membership?', $cancel->section);
        self::assertSame("## Can I cancel my membership?\n\nYes, within **14 days** of signing up, with a full refund.", $cancel->content);

        $plans = $search->search('plan prices', 1)[0];
        self::assertSame('Plans', $plans->section);
        self::assertStringContainsString("### Plan prices\n\nFrom 10 to 40 a month.", $plans->content);
    }

    /** @return array<string, KnowledgeDocument> */
    private function documents(): array
    {
        $documents = [];
        foreach ((new MarkdownKnowledgeSource(self::DIR))->documents() as $document) {
            $documents[$document->id] = $document;
        }

        return $documents;
    }
}
