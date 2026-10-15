<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Bridge;

use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

final class ModelCatalogPrependTest extends TestCase
{
    public function testTheDefaultClaudeModelsAreDeclaredForTheAiBundle(): void
    {
        $builder = new ContainerBuilder();
        $builder->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'ai';
            }
        });

        $this->prepend($builder);

        /** @var array{model: array{anthropic: array<string, array{class: string, capabilities: list<string>}>}} $config */
        $config = $builder->getExtensionConfig('ai')[0];
        $models = $config['model']['anthropic'];
        foreach (['claude-haiku-5-5', 'claude-sonnet-5-5'] as $model) {
            self::assertSame(Claude::class, $models[$model]['class']);
            self::assertContains('tool-calling', $models[$model]['capabilities']);
        }
    }

    public function testNothingIsDeclaredWithoutTheAiBundle(): void
    {
        $builder = new ContainerBuilder();

        $this->prepend($builder);

        self::assertSame([], $builder->getExtensionConfig('ai'));
    }

    private function prepend(ContainerBuilder $builder): void
    {
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new OdiseoAiConversationalAgentBundle())->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($builder);
    }
}
