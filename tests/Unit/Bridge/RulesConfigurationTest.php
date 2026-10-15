<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Bridge;

use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\OdiseoAiConversationalAgentBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

/** Configured rules that break a cap fail when the container is built, never in a turn. */
final class RulesConfigurationTest extends TestCase
{
    public function testIdsAreKeptAsWrittenAndTheCategoryDefaultsToOther(): void
    {
        $config = $this->process(['rules' => ['no-codes' => ['title' => 'No codes', 'text' => 'No discount codes.']]]);

        self::assertSame(['no-codes' => ['title' => 'No codes', 'text' => 'No discount codes.', 'category' => 'other', 'enabled' => true]], $config['rules']);
    }

    public function testARuleOverTheLengthCapFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Rule "long" is 11 characters long; the limit is 10');

        $this->process([
            'limits' => ['max_rule_chars' => 10],
            'rules' => ['long' => ['title' => 'Long', 'text' => 'Eleven char']],
        ]);
    }

    public function testMoreEnabledRulesThanTheCapFailButDisabledOnesDoNotCount(): void
    {
        $rules = [
            'a' => ['title' => 'A', 'text' => 'One.'],
            'b' => ['title' => 'B', 'text' => 'Two.', 'enabled' => false],
        ];
        $this->process(['limits' => ['max_rules' => 1], 'rules' => $rules]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('2 rules are enabled; the limit is 1');

        $rules['b']['enabled'] = true;
        $this->process(['limits' => ['max_rules' => 1], 'rules' => $rules]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<mixed>
     */
    private function process(array $config): array
    {
        $extension = (new OdiseoAiConversationalAgentBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
