<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Bridge;

use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\KeyedPlatformPass;
use Odiseo\AiConversationalAgentBundle\Provider\Platform\KeyedPlatform;
use Odiseo\AiConversationalAgentBundle\Provider\Platform\PlatformFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class KeyedPlatformPassTest extends TestCase
{
    public function testTheFactoryRepeatsTheConfiguredPlatformsArgumentsAfterTheKey(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('ai.platform.anthropic', (new Definition(\stdClass::class))
            ->setFactory('Vendor\\Factory::createPlatform')
            ->setArguments(['%env(KEY)%', new Reference('http_client'), 'none']));
        $container->setDefinition('keyed', new Definition(KeyedPlatform::class, [new Reference('ai.platform.anthropic'), 'anthropic', null, null]));

        (new KeyedPlatformPass())->process($container);

        $factory = $container->getDefinition('keyed')->getArgument(2);
        self::assertInstanceOf(Definition::class, $factory);
        self::assertSame(PlatformFactory::class, $factory->getClass());
        self::assertSame(['Vendor\\Factory', 'createPlatform'], $factory->getArgument(0));
        self::assertEquals([new Reference('http_client'), 'none'], $factory->getArgument(1));
    }

    public function testAPlatformWithoutAFactoryKeepsItsKey(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('ai.platform.anthropic', new Definition(\stdClass::class, ['key']));
        $container->setDefinition('keyed', new Definition(KeyedPlatform::class, [new Reference('ai.platform.anthropic'), 'anthropic', null, null]));

        (new KeyedPlatformPass())->process($container);

        self::assertNull($container->getDefinition('keyed')->getArgument(2));
    }
}
