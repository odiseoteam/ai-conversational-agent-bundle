<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Eval;

use Odiseo\AiAgentBundle\Skill\SkillCapability;
use Odiseo\AiAgentBundle\Streaming\AgentEvent;
use Odiseo\AiAgentBundle\Streaming\EventType;

/**
 * What a graded turn produced. Graders read this rather than the transcript, because what is
 * being asserted is the calls the agent made and the state they left, not its wording.
 */
final class TurnRecording
{
    /** @var list<array{tool: string, input: array<string, mixed>}> */
    public array $toolCalls = [];

    /** @var list<string> */
    public array $components = [];

    /** @var list<string> */
    public array $skillsLoaded = [];

    /** @var list<array{tool: string, status: string, reason: string|null}> */
    public array $toolResults = [];

    public string $reply = '';

    public ?string $stopReason = null;

    /** @var array<string, int> */
    public array $usage = [];

    public function record(AgentEvent $event): void
    {
        switch ($event->type) {
            case EventType::TextDelta:
                $this->reply .= (string) ($event->data['text'] ?? '');
                break;
            case EventType::ToolCall:
                $tool = (string) ($event->data['tool'] ?? '');
                $input = \is_array($event->data['input'] ?? null) ? $event->data['input'] : [];
                $this->toolCalls[] = ['tool' => $tool, 'input' => $input];
                if (SkillCapability::TOOL === $tool && isset($input['skill_name'])) {
                    $this->skillsLoaded[] = (string) $input['skill_name'];
                }
                break;
            case EventType::ToolResult:
                $this->toolResults[] = [
                    'tool' => (string) ($event->data['tool'] ?? ''),
                    'status' => (string) ($event->data['status'] ?? ''),
                    'reason' => isset($event->data['reason']) ? (string) $event->data['reason'] : null,
                ];
                break;
            case EventType::Ui:
                $this->components[] = (string) ($event->data['component'] ?? '');
                break;
            case EventType::TurnComplete:
                $this->stopReason = isset($event->data['stop_reason']) ? (string) $event->data['stop_reason'] : null;
                $this->usage = \is_array($event->data['usage'] ?? null) ? $event->data['usage'] : [];
                break;
            default:
                break;
        }
    }

    /** @return list<string> */
    public function toolNames(): array
    {
        return array_map(static fn (array $call): string => $call['tool'], $this->toolCalls);
    }

    public function firstTool(): ?string
    {
        return $this->toolCalls[0]['tool'] ?? null;
    }
}
