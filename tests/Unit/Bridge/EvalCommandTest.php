<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Bridge;

use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Command\EvalCommand;
use Odiseo\AiConversationalAgentBundle\Eval\EvalRunner;
use Odiseo\AiConversationalAgentBundle\Host\NullConsoleEnvironment;
use Odiseo\AiConversationalAgentBundle\Host\PrincipalResolver;
use Odiseo\AiConversationalAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\AgentBuilder;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\DirectoryCapability;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** A live run writes its recordings; a replay grades them again without a model call. */
final class EvalCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/odiseo-eval-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/cases', 0o777, true);
        file_put_contents($this->dir.'/cases/directory.json', json_encode([
            ['id' => 'finds-001', 'priority' => 'high', 'turns' => ['show me'], 'expected' => ['calls_tool' => ['find_records']]],
            ['id' => 'later-002', 'turns' => ['hi'], 'expected' => [], 'skip' => 'not yet'],
        ]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir.'/*') ?: [] as $dir) {
            rmdir($dir);
        }
        rmdir($this->dir);
    }

    public function testALiveRunIsWrittenAndReplayedWithoutTheModel(): void
    {
        $live = $this->tester([
            FakeProvider::toolCall('find_records', ['query' => 'a'], 'tu-1'),
            FakeProvider::text('Found one.'),
        ]);
        self::assertSame(Command::SUCCESS, $live->execute(['--dir' => $this->dir.'/cases']));
        self::assertStringContainsString('1 passed, 0 failed, 0 errors, 1 skipped', $live->getDisplay());

        $runs = glob($this->dir.'/runs/eval-*.json') ?: [];
        self::assertCount(1, $runs);

        $replay = $this->tester([]);
        self::assertSame(Command::SUCCESS, $replay->execute(['--dir' => $this->dir.'/cases', '--replay' => $runs[0]]));
        self::assertStringContainsString('Replayed 2 of the 2 cases', $replay->getDisplay());
        self::assertStringContainsString('1 passed, 0 failed, 0 errors, 1 skipped', $replay->getDisplay());
    }

    public function testAFailedCaseFailsTheCommand(): void
    {
        $tester = $this->tester([FakeProvider::text('No idea.')]);

        self::assertSame(Command::FAILURE, $tester->execute(['--dir' => $this->dir.'/cases', '--case' => ['finds-001'], '--output' => $this->dir.'/runs/out.json']));
        self::assertStringContainsString('expected a call to find_records', $tester->getDisplay());
    }

    /** @param list<\Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse> $responses */
    private function tester(array $responses): CommandTester
    {
        $builder = new AgentBuilder(new FakeProvider($responses), extra: [new DirectoryCapability()]);
        $guest = new class implements PrincipalResolver {
            public function principalId(): string
            {
                return 'guest';
            }

            public function isGuest(): bool
            {
                return true;
            }
        };
        $runner = new EvalRunner($builder->turnRunner(), $builder->sessionStore, $builder->memoryStore, $builder->ledger, $guest, pause: static function (int $seconds): void {
        });

        return new CommandTester(new EvalCommand($runner, new NullConsoleEnvironment(), $builder->config, null, $this->dir.'/runs', 'claude-sonnet-5'));
    }
}
