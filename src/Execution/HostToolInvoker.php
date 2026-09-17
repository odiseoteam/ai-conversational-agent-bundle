<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Execution;

use Odiseo\AiAgentBundle\Capability\Limits;
use Odiseo\AiAgentBundle\Capability\ToolContext;
use Odiseo\AiAgentBundle\Fencing\Fence;
use Odiseo\AiAgentBundle\Session\SessionContext;
use Odiseo\AiAgentBundle\Session\SessionRecord;
use Odiseo\AiAgentBundle\Streaming\ToolOutcome;

/**
 * A tool run by the host outside a turn — a button in the interface, not the model — through
 * the same executor, so every gate holds whoever asks. When the call went through, the note
 * the host wrote is queued on the record for the next turn, which is how the model learns what
 * the person did between replies.
 *
 * What the tool raised propagates: the host answers a person, not a model, so it decides what
 * a failure looks like. A presentation tool cannot be invoked here: there is no turn to render
 * into.
 */
final class HostToolInvoker
{
    public const NOTE_MAX_CHARS = 300;

    public function __construct(
        private readonly ToolExecutor $executor,
        private readonly Fence $fence,
        private readonly Limits $limits,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param string|null          $note what to tell the model on its next turn when the call went
     *                                   through; it enters the transcript unfenced, so it is
     *                                   sanitized like any host string
     */
    public function invoke(string $tool, array $input, SessionRecord $record, SessionContext $session, ?string $note = null): ToolOutcome
    {
        if ($this->executor->presents($tool)) {
            throw new \InvalidArgumentException(\sprintf('%s is a presentation tool; the host cannot invoke it outside a turn.', $tool));
        }

        $context = new ToolContext($session, $record->state, $this->fence, $this->limits);
        $outcome = $this->executor->dispatch($tool, $input, $context);

        if (!$outcome->refused() && null !== $note) {
            $text = $this->fence->sanitizeText($note, self::NOTE_MAX_CHARS);
            if ('' !== $text) {
                $record->pendingAppEvents[] = $text;
            }
        }

        return $outcome;
    }
}
