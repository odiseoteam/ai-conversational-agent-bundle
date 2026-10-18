<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Knowledge;

use Odiseo\AiConversationalAgentBundle\Content\InMemoryVersionStore;
use Odiseo\AiConversationalAgentBundle\Knowledge\GuideIndex;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeDocument;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeKind;
use Odiseo\AiConversationalAgentBundle\Knowledge\KnowledgeSourceInterface;
use Odiseo\AiConversationalAgentBundle\Knowledge\VersionedKnowledgeSource;
use PHPUnit\Framework\TestCase;

final class VersionedKnowledgeSourceTest extends TestCase
{
    public function testAPublishedVersionReplacesTheFileDocument(): void
    {
        $store = new InMemoryVersionStore();
        $store->publish('knowledge', 'shipping', ['title' => 'Shipping', 'kind' => 'policy', 'when' => '', 'body' => 'Free from $90.000.', 'url' => null, 'enabled' => true], hasDefault: true);

        self::assertSame(['Free from $90.000.', 'Pick a height.'], $this->bodies($store));
    }

    public function testADisabledVersionLeavesTheDocumentOut(): void
    {
        $store = new InMemoryVersionStore();
        $store->publish('knowledge', 'trees', ['title' => 'Trees', 'kind' => 'guide', 'when' => '', 'body' => 'x', 'url' => null, 'enabled' => false], hasDefault: true);

        self::assertSame(['Free from $75.000.'], $this->bodies($store));
    }

    public function testBackToTheDefaultLeavesTheFile(): void
    {
        $store = new InMemoryVersionStore();
        $store->publish('knowledge', 'shipping', ['title' => 'Shipping', 'kind' => 'policy', 'body' => 'Edited.'], hasDefault: true);
        $store->publish('knowledge', 'shipping', null, hasDefault: true);

        self::assertSame(['Free from $75.000.', 'Pick a height.'], $this->bodies($store));
    }

    public function testADocumentOnlyInVersionsIsAddedAndAGuideReachesTheIndex(): void
    {
        $store = new InMemoryVersionStore();
        $index = new GuideIndex([$this->source($store)], revisions: $store);
        self::assertSame(['trees'], array_map(static fn (KnowledgeDocument $d): string => $d->id, $index->guides()));

        $store->publish('knowledge', 'lights', ['title' => 'Lights', 'kind' => 'guide', 'when' => 'Choosing lights.', 'body' => 'Five metres per metre.', 'url' => null, 'enabled' => true]);

        self::assertSame(['lights', 'trees'], array_map(static fn (KnowledgeDocument $d): string => $d->id, $index->guides()));
        self::assertSame('Choosing lights.', $index->guides()[0]->when);
    }

    public function testWithoutFilesOnlyTheVersionsCount(): void
    {
        $store = new InMemoryVersionStore();
        $store->publish('knowledge', 'faq', ['title' => 'FAQ', 'kind' => 'snippet', 'body' => 'Open on weekdays.']);

        $documents = [...(new VersionedKnowledgeSource($store))->documents()];

        self::assertCount(1, $documents);
        self::assertSame(KnowledgeKind::Snippet, $documents[0]->kind);
    }

    private function source(InMemoryVersionStore $store): VersionedKnowledgeSource
    {
        $files = new class implements KnowledgeSourceInterface {
            public function documents(): iterable
            {
                yield new KnowledgeDocument('shipping', 'Shipping', 'Free from $75.000.');
                yield new KnowledgeDocument('trees', 'Trees', 'Pick a height.', KnowledgeKind::Guide, 'Choosing a tree.');
            }
        };

        return new VersionedKnowledgeSource($store, $files);
    }

    /** @return list<string> */
    private function bodies(InMemoryVersionStore $store): array
    {
        return array_values(array_map(static fn (KnowledgeDocument $document): string => $document->body, [...$this->source($store)->documents()]));
    }
}
