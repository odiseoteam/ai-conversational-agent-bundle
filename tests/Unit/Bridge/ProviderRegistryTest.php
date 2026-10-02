<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Bridge;

use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\ProviderRegistry;
use Odiseo\AiConversationalAgentBundle\Provider\Fake\FakeProvider;
use Odiseo\AiConversationalAgentBundle\Provider\ProviderCapabilities;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ProviderRegistryTest extends TestCase
{
    public function testARoleGetsTheAdapterOfItsPlatformWithTheDeploymentsCapabilities(): void
    {
        /** @var ServiceLocator<FakeProvider> $providers */
        $providers = new ServiceLocator(['anthropic' => static fn (): FakeProvider => new FakeProvider([], new ProviderCapabilities(thinking: true))]);
        $registry = new ProviderRegistry($providers, ['claude-sonnet-6' => ['thinking' => false]]);

        self::assertFalse($registry->get('anthropic')->capabilities('claude-sonnet-6')->thinking);
    }

    public function testAPlatformWithoutAnAdapterIsNamedWithTheInstalledOnes(): void
    {
        /** @var ServiceLocator<FakeProvider> $providers */
        $providers = new ServiceLocator(['anthropic' => static fn (): FakeProvider => new FakeProvider()]);
        $registry = new ProviderRegistry($providers);

        $this->expectExceptionMessage('No model provider for the platform "openai". Installed: anthropic.');

        $registry->get('openai');
    }
}
