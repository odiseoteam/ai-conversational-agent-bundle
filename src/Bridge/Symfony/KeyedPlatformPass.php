<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony;

use Odiseo\AiConversationalAgentBundle\Provider\Platform\KeyedPlatform;
use Odiseo\AiConversationalAgentBundle\Provider\Platform\PlatformFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives each keyed platform a way to rebuild its configured platform with another key: the same
 * factory and the same arguments after the key, read from the platform's definition. A platform
 * the host defined some other way keeps its own key only.
 */
final class KeyedPlatformPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            if (KeyedPlatform::class !== $definition->getClass()) {
                continue;
            }

            $configured = $definition->getArgument(0);
            if (!$configured instanceof Reference || !$container->hasDefinition((string) $configured)) {
                continue;
            }

            $platform = $container->getDefinition((string) $configured);
            $factory = $platform->getFactory();
            $arguments = array_values($platform->getArguments());
            if (null === $factory || [] === $arguments) {
                continue;
            }
            if (\is_array($factory) && $factory[0] instanceof Reference) {
                continue;
            }

            $definition->setArgument(2, new Definition(PlatformFactory::class, [$factory, \array_slice($arguments, 1)]));
        }
    }
}
