<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Capability;

use Odiseo\AiConversationalAgentBundle\Grounding\GroundingRule;
use Odiseo\AiConversationalAgentBundle\Presentation\PresentationComponent;

/**
 * The capabilities one deployment registered, and the surfaces derived from them. Built once
 * per process: the tool array and the prompt fragments are prompt bytes, so they must be the
 * same on every request of a deployment.
 */
final class CapabilityRegistry
{
    /** @var list<Capability> */
    private array $capabilities;

    /** @var array<string, Capability>|null */
    private ?array $byTool = null;

    /** @param iterable<Capability> $capabilities */
    public function __construct(iterable $capabilities)
    {
        $list = \is_array($capabilities) ? array_values($capabilities) : iterator_to_array($capabilities, false);
        usort($list, static fn (Capability $a, Capability $b): int => $a->name() <=> $b->name());

        $this->capabilities = $list;
    }

    /** @return list<Capability> */
    public function all(): array
    {
        return $this->capabilities;
    }

    public function has(string $name): bool
    {
        foreach ($this->capabilities as $capability) {
            if ($capability->name() === $name) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ToolSpec> */
    public function tools(): array
    {
        $tools = [];
        foreach ($this->capabilities as $capability) {
            foreach ($capability->tools() as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    public function capabilityForTool(string $tool): ?Capability
    {
        if (null === $this->byTool) {
            $this->byTool = [];
            foreach ($this->capabilities as $capability) {
                foreach ($capability->tools() as $spec) {
                    $this->byTool[$spec->name] = $capability;
                }
            }
        }

        return $this->byTool[$tool] ?? null;
    }

    /** @return list<PromptFragment> ordered by section, then priority, then capability name */
    public function promptFragments(): array
    {
        $fragments = [];
        foreach ($this->capabilities as $index => $capability) {
            foreach ($capability->promptFragments() as $position => $fragment) {
                $fragments[] = [$fragment->section->order(), $fragment->priority, $index, $position, $fragment];
            }
        }

        usort($fragments, static function (array $a, array $b): int {
            return [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]];
        });

        return array_map(static fn (array $row): PromptFragment => $row[4], $fragments);
    }

    /** @return list<GroundingRule> in capability order, each capability's rules in its own order */
    public function groundingRules(): array
    {
        $rules = [];
        foreach ($this->capabilities as $capability) {
            foreach ($capability->groundingRules() as $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /** @return array<string, PresentationComponent> keyed by tool name */
    public function components(): array
    {
        $components = [];
        foreach ($this->capabilities as $capability) {
            foreach ($capability->components() as $component) {
                $components[$component->tool] = $component;
            }
        }

        return $components;
    }
}
