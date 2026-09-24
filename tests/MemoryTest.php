<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Fencing\Fence;
use Odiseo\AiConversationalAgentBundle\Memory\InMemoryMemoryStore;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryCategory;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryFact;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryRuntime;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryWriteFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemoryTest extends TestCase
{
    public function testAnOrdinaryFactIsStored(): void
    {
        [$runtime, $store] = $this->runtime();

        $outcome = $runtime->save('visitor-1', 'tag', ['key' => 'sector', 'value' => 'Wholesale']);

        self::assertFalse($outcome->refused());
        self::assertCount(1, $store->all('visitor-1'));
    }

    #[DataProvider('identifiers')]
    public function testAnIdentifierIsRefusedWithoutTellingThePerson(string $value): void
    {
        [$runtime, $store] = $this->runtime();

        $outcome = $runtime->save('visitor-1', 'tag', ['key' => 'contact', 'value' => $value]);

        self::assertSame([], $store->all('visitor-1'), 'nothing is stored');
        self::assertFalse($outcome->isError, 'and it is not an error the person hears about');
        self::assertStringContainsString('do not mention it', $outcome->resultText);
    }

    /** @return iterable<string, array{string}> */
    public static function identifiers(): iterable
    {
        yield 'email' => ['Write to me at juan@example.com'];
        yield 'phone' => ['My phone is 11 5555 4444'];
        yield 'document' => ['ID 30123456'];
        yield 'password' => ['my password: hunter2'];
    }

    public function testASaveUnderAKnownKeyReplacesTheEarlierFact(): void
    {
        [$runtime, $store] = $this->runtime();

        $runtime->save('visitor-1', 'tag', ['key' => 'sector', 'value' => 'Retail']);
        $runtime->save('visitor-1', 'tag', ['key' => 'sector', 'value' => 'Services']);

        self::assertCount(1, $store->all('visitor-1'));
        self::assertSame('Services', $store->all('visitor-1')[0]->value);
    }

    public function testConstraintsAreInjectedBeforeTheRest(): void
    {
        [$runtime, $store] = $this->runtime(new AgentConfig(memoryTierOneCap: 2));

        $store->save('visitor-1', new MemoryFact('a', 'uno', MemoryCategory::Preference, new \DateTimeImmutable()));
        $store->save('visitor-1', new MemoryFact('b', 'dos', MemoryCategory::Preference, new \DateTimeImmutable()));
        $store->save('visitor-1', new MemoryFact('c', 'three', MemoryCategory::Constraint, new \DateTimeImmutable()));

        $keys = array_map(static fn (MemoryFact $f): string => $f->key, $runtime->tierOne('visitor-1'));

        self::assertSame('c', $keys[0]);
        self::assertCount(2, $keys);
    }

    public function testAFactPastItsRetentionIsNeitherInjectedNorRecalled(): void
    {
        [$runtime, $store] = $this->runtime(new AgentConfig(memoryRetentionDays: 30));

        $store->save('visitor-1', new MemoryFact('old-fact', 'something', MemoryCategory::Preference, new \DateTimeImmutable('-60 days')));
        $store->save('visitor-1', new MemoryFact('nuevo', 'otra', MemoryCategory::Preference, new \DateTimeImmutable()));

        $keys = array_map(static fn (MemoryFact $f): string => $f->key, $runtime->tierOne('visitor-1'));

        self::assertSame(['nuevo'], $keys);
        self::assertStringNotContainsString('old-fact', $runtime->recall('visitor-1', ['query' => 'something'])->resultText);
    }

    public function testRecalledFactsCarryTheSessionThatWroteThem(): void
    {
        [$runtime] = $this->runtime();

        $runtime->save('visitor-1', 'sess-tag', ['key' => 'sector', 'value' => 'Retail']);

        self::assertStringContainsString('sess-tag', $runtime->recall('visitor-1', ['query' => 'sector'])->resultText);
    }

    public function testTheWriteFilterCannotBeWeakenedByConfiguration(): void
    {
        $filter = new MemoryWriteFilter(['/never/u']);

        self::assertFalse($filter->allows('juan@example.com'), 'the identifier defaults still hold');
        self::assertFalse($filter->allows('this never goes'), 'and the deployment pattern is added');
        self::assertTrue($filter->allows('They work with Symfony'));
    }

    /** @return array{0: MemoryRuntime, 1: InMemoryMemoryStore} */
    private function runtime(?AgentConfig $config = null): array
    {
        $store = new InMemoryMemoryStore();
        $config ??= new AgentConfig();

        return [
            new MemoryRuntime($store, $config, new Fence('site_content', 'notice'), 'Return []'),
            $store,
        ];
    }
}
