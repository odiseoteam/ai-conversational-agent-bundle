<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Provider;

use Odiseo\AiConversationalAgentBundle\Provider\ApiKeyResolverInterface;
use Odiseo\AiConversationalAgentBundle\Provider\Platform\KeyedPlatform;
use Odiseo\AiConversationalAgentBundle\Provider\Platform\PlatformFactory;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

final class KeyedPlatformTest extends TestCase
{
    public function testWithoutAKeyTheConfiguredPlatformAnswers(): void
    {
        $platform = new KeyedPlatform(self::platform('configured'), 'anthropic', self::factory(), $this->keys(null));

        $this->expectExceptionMessage('configured');
        $platform->invoke('claude-haiku-5-5', []);
    }

    public function testAResolvedKeyGetsAPlatformBuiltWithIt(): void
    {
        $platform = new KeyedPlatform(self::platform('configured'), 'anthropic', self::factory(), $this->keys('sk-store'));

        $this->expectExceptionMessage('built with sk-store and the arguments cache:none');
        $platform->invoke('claude-haiku-5-5', []);
    }

    public function testWithoutAFactoryTheKeyIsIgnored(): void
    {
        $platform = new KeyedPlatform(self::platform('configured'), 'anthropic', null, $this->keys('sk-store'));

        $this->expectExceptionMessage('configured');
        $platform->invoke('claude-haiku-5-5', []);
    }

    private static function factory(): PlatformFactory
    {
        return new PlatformFactory(
            static fn (string $key, string $cache): PlatformInterface => self::platform(\sprintf('built with %s and the arguments %s', $key, $cache)),
            ['cache:none'],
        );
    }

    private static function platform(string $label): PlatformInterface
    {
        return new class($label) implements PlatformInterface {
            public function __construct(private readonly string $label)
            {
            }

            public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
            {
                throw new \RuntimeException($this->label);
            }

            public function getModelCatalog(): ModelCatalogInterface
            {
                throw new \LogicException('Not used.');
            }
        };
    }

    private function keys(?string $key): ApiKeyResolverInterface
    {
        return new class($key) implements ApiKeyResolverInterface {
            public function __construct(private readonly ?string $key)
            {
            }

            public function apiKey(string $platform): ?string
            {
                return 'anthropic' === $platform ? $this->key : null;
            }
        };
    }
}
