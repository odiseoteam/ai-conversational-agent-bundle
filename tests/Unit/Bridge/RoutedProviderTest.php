<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Bridge;

use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\ProviderRegistry;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\RoutedProvider;
use Odiseo\AiConversationalAgentBundle\Config\AgentConfig;
use Odiseo\AiConversationalAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RoutedProviderTest extends TestCase
{
    public function testEachRoleFollowsThePlatformInForce(): void
    {
        /** @var ServiceLocator<FakeProvider> $providers */
        $providers = new ServiceLocator([
            'anthropic' => static fn (): FakeProvider => new FakeProvider([], new ProviderCapabilities(thinking: true)),
            'openai' => static fn (): FakeProvider => new FakeProvider([], new ProviderCapabilities(thinking: false)),
        ]);
        $registry = new ProviderRegistry($providers);
        $config = new AgentConfig(platform: 'openai', memoryPlatform: 'anthropic');

        self::assertFalse((new RoutedProvider($registry, $config, RoutedProvider::TURN))->capabilities('gpt-5.6-luna')->thinking);
        self::assertTrue((new RoutedProvider($registry, $config, RoutedProvider::MEMORY))->capabilities('claude-haiku-5-5')->thinking);
    }
}
