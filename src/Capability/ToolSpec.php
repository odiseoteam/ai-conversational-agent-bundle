<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Capability;

/**
 * One tool as the model sees it. A capability lists its tools in a fixed order; the surface
 * is the union of what every registered capability declares, so a deployment without a
 * capability has no tool for it and no prompt line about it.
 */
final readonly class ToolSpec
{
    /**
     * @param array<string, mixed>      $inputSchema        JSON schema for the tool's arguments
     * @param array<string, mixed>|null $providerDefinition set for a server-side tool the provider runs itself
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public bool $wantsStatusLine = true,
        public ?array $providerDefinition = null,
    ) {
    }

    public function isServerTool(): bool
    {
        return null !== $this->providerDefinition;
    }
}
