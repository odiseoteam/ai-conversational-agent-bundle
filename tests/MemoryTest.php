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

        $outcome = $runtime->save('visitor-1', 'tag', ['key' => 'sector', 'value' => 'Comercio mayorista']);

        self::assertFalse($outcome->refused());
        self::assertCount(1, $store->all('visitor-1'));
    }

    #[DataProvider('identifiers')]
    public function testAnIdentifierIsRefusedWithoutTellingThePerson(string $value): void
    {
        [$runtime, $store] = $this->runtime();

        $outcome = $runtime->save('visitor-1', 'tag', ['key' => 'contacto', 'value' => $value]);

        self::assertSame([], $store->all('visitor-1'), 'nothing is stored');
        self::assertFalse($outcome->isError, 'and it is not an error the person hears about');
        self::assertStringContainsString('do not mention it', $outcome->resultText);
    }

    /** @return iterable<string, array{string}> */
    public static function identifiers(): iterable
    {
        yield 'email' => ['Escribime a juan@example.com'];
        yield 'phone' => ['Mi teléfono es 11 5555 4444'];
        yield 'document' => ['DNI 30123456'];
        yield 'password' => ['la clave: hunter2'];
    }

    public function testASaveUnderAKnownKeyReplacesTheEarlierFact(): void
    {
        [$runtime, $store] = $this->runtime();

        $runtime->save('visitor-1', 'tag', ['key' => 'sector', 'value' => 'Comercio']);
        $runtime->save('visitor-1', 'tag', ['key' => 'sector', 'value' => 'Servicios']);

        self::assertCount(1, $store->all('visitor-1'));
        self::assertSame('Servicios', $store->all('visitor-1')[0]->value);
    }

    public function testConstraintsAreInjectedBeforeTheRest(): void
    {
        [$runtime, $store] = $this->runtime(new AgentConfig(memoryTierOneCap: 2));

        $store->save('visitor-1', new MemoryFact('a', 'uno', MemoryCategory::Preference, new \DateTimeImmutable()));
        $store->save('visitor-1', new MemoryFact('b', 'dos', MemoryCategory::Preference, new \DateTimeImmutable()));
        $store->save('visitor-1', new MemoryFact('c', 'tres', MemoryCategory::Constraint, new \DateTimeImmutable()));

        $keys = array_map(static fn (MemoryFact $f): string => $f->key, $runtime->tierOne('visitor-1'));

        self::assertSame('c', $keys[0]);
        self::assertCount(2, $keys);
    }

    public function testAFactPastItsRetentionIsNeitherInjectedNorRecalled(): void
    {
        [$runtime, $store] = $this->runtime(new AgentConfig(memoryRetentionDays: 30));

        $store->save('visitor-1', new MemoryFact('viejo', 'algo', MemoryCategory::Preference, new \DateTimeImmutable('-60 days')));
        $store->save('visitor-1', new MemoryFact('nuevo', 'otra', MemoryCategory::Preference, new \DateTimeImmutable()));

        $keys = array_map(static fn (MemoryFact $f): string => $f->key, $runtime->tierOne('visitor-1'));

        self::assertSame(['nuevo'], $keys);
        self::assertStringNotContainsString('viejo', $runtime->recall('visitor-1', ['query' => 'algo'])->resultText);
    }

    public function testRecalledFactsCarryTheSessionThatWroteThem(): void
    {
        [$runtime] = $this->runtime();

        $runtime->save('visitor-1', 'sess-tag', ['key' => 'sector', 'value' => 'Comercio']);

        self::assertStringContainsString('sess-tag', $runtime->recall('visitor-1', ['query' => 'sector'])->resultText);
    }

    public function testTheWriteFilterCannotBeWeakenedByConfiguration(): void
    {
        $filter = new MemoryWriteFilter(['/nunca/u']);

        self::assertFalse($filter->allows('juan@example.com'), 'the identifier defaults still hold');
        self::assertFalse($filter->allows('esto nunca va'), 'and the deployment pattern is added');
        self::assertTrue($filter->allows('Trabajan con Symfony'));
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
