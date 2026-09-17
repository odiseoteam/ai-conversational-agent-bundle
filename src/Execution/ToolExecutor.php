<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Execution;

use Odiseo\AiAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiAgentBundle\Capability\ToolContext;
use Odiseo\AiAgentBundle\Fencing\Sanitizer;
use Odiseo\AiAgentBundle\Presentation\PresentationComponent;
use Odiseo\AiAgentBundle\Presentation\PresentationRunner;
use Odiseo\AiAgentBundle\Streaming\AgentEvent;
use Odiseo\AiAgentBundle\Streaming\ToolOutcome;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Dispatch, the failure ladder, presentation and the status line, in one place, so a tool
 * result is the same bytes whichever path called it.
 *
 * execute() never throws: a tool that fails is reported to the model as unavailable and logged
 * here, because a failed read must not end the turn.
 */
final class ToolExecutor
{
    /** @var array<string, PresentationComponent>|null */
    private ?array $components = null;

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly ExecutorWording $wording = new ExecutorWording(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function presents(string $tool): bool
    {
        return isset($this->components()[$tool]);
    }

    /**
     * True when a call may sit in the round that ends the turn without a closing model call: a
     * presentation call that rendered and left the model nothing to answer.
     */
    public function endsClean(string $tool, ToolOutcome $outcome): bool
    {
        return $this->presents($tool)
            && !$outcome->refused()
            && $outcome->resultText === $this->wording->displayedText;
    }

    /**
     * The call's arguments without the status line, and that line sanitized for the host.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    public function splitStatus(string $tool, array $input): array
    {
        if ($this->presents($tool) || !\array_key_exists(ToolSurface::STATUS_FIELD, $input)) {
            return [$input, null];
        }

        $raw = $input[ToolSurface::STATUS_FIELD];
        unset($input[ToolSurface::STATUS_FIELD]);

        // Fence markers out like any model string, then one-line hygiene and the cap.
        $status = Sanitizer::label($raw, ToolSurface::STATUS_MAX_CHARS);

        return [$input, '' === $status ? null : $status];
    }

    /**
     * The tool_call event for the host: the arguments the tool will get, and the status line
     * beside them as its label.
     *
     * @param array<string, mixed> $input
     */
    public function toolCallEvent(string $tool, string $toolUseId, array $input): AgentEvent
    {
        [$arguments, $status] = $this->splitStatus($tool, $input);

        return AgentEvent::toolCall($tool, $toolUseId, $arguments, $status);
    }

    /** @param array<string, mixed> $input */
    public function execute(string $tool, array $input, ToolContext $context): ToolOutcome
    {
        try {
            return $this->dispatch($tool, $input, $context);
        } catch (\Throwable $error) {
            $capability = $this->capabilities->capabilityForTool($tool);
            if ($capability instanceof DomainErrorMapper
                && null !== $outcome = $capability->mapError($error, $this->wording)) {
                return $outcome;
            }

            $this->logger->warning('tool {tool} failed and is reported as unavailable', [
                'tool' => $tool,
                'session' => $context->session->sessionTag(),
                'exception' => $error,
            ]);

            return ToolOutcome::error(str_replace('{name}', $tool, $this->wording->unavailableText));
        }
    }

    /**
     * execute() without the failure ladder: what the tool raised propagates, so a host
     * prefetching a read can tell a failed tool from a result the tool wrote.
     *
     * @param array<string, mixed> $input
     */
    public function dispatch(string $tool, array $input, ToolContext $context): ToolOutcome
    {
        [$input] = $this->splitStatus($tool, $input);

        $component = $this->components()[$tool] ?? null;
        if (null !== $component) {
            return $this->present($component, $input, $context);
        }

        $capability = $this->capabilities->capabilityForTool($tool);
        if (null === $capability) {
            return ToolOutcome::error(\sprintf('Unknown tool: %s', $tool));
        }

        return $capability->execute($tool, $input, $context);
    }

    /** @param array<string, mixed> $input */
    private function present(PresentationComponent $component, array $input, ToolContext $context): ToolOutcome
    {
        $isChips = ChipComponent::TOOL === $component->tool;
        $limit = $context->limits->maxComponentsPerTurn;

        if (!$isChips && $context->scope->componentsPresented() >= $limit) {
            return ToolOutcome::held(
                'component_cap',
                str_replace('{count}', (string) $limit, $this->wording->tooManyComponentsText),
            );
        }

        $outcome = PresentationRunner::run($component, $input, $context, $this->wording->displayedText);
        if (!$outcome->refused()) {
            $context->scope->countComponent($isChips);
        }

        return $outcome;
    }

    /** @return array<string, PresentationComponent> */
    private function components(): array
    {
        return $this->components ??= $this->capabilities->components();
    }
}
