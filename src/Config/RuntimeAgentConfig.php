<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Config;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The deployment's settings with the host's runtime changes on top. The result is kept per set
 * of changes, so reading it many times in a turn costs one lookup of the changes each time.
 *
 * A change that AgentConfig rejects (an unknown effort, a wrong type) is dropped and logged:
 * a bad saved value falls back to the configuration instead of failing the turn.
 */
final class RuntimeAgentConfig implements AgentConfigProviderInterface
{
    private const MAX_KEPT = 16;

    /** @var array<string, AgentConfig> */
    private array $resolved = [];

    /** @var list<string>|null */
    private ?array $fields = null;

    public function __construct(
        private readonly AgentConfig $defaults,
        private readonly ConfigOverridesInterface $overrides = new NullConfigOverrides(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function agentConfig(): AgentConfig
    {
        $changes = array_intersect_key($this->overrides->changes(), array_flip($this->fields()));
        if ([] === $changes) {
            return $this->defaults;
        }

        $key = md5(serialize($changes));
        if (!isset($this->resolved[$key]) && \count($this->resolved) >= self::MAX_KEPT) {
            $this->resolved = [];
        }

        return $this->resolved[$key] ??= $this->apply($changes);
    }

    /** @param array<string, mixed> $changes */
    private function apply(array $changes): AgentConfig
    {
        try {
            return $this->defaults->with($changes);
        } catch (\Throwable $e) {
            $this->logger->warning('Runtime settings left out, the configured ones apply: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return $this->defaults;
        }
    }

    /** @return list<string> */
    private function fields(): array
    {
        if (null === $this->fields) {
            $constructor = (new \ReflectionClass(AgentConfig::class))->getConstructor();
            $this->fields = null === $constructor ? [] : array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $constructor->getParameters(),
            );
        }

        return $this->fields;
    }
}
