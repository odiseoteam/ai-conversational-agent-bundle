<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

use Odiseo\AiConversationalAgentBundle\Skill\SkillCapability;
use Odiseo\AiConversationalAgentBundle\Streaming\AgentEvent;
use Odiseo\AiConversationalAgentBundle\Streaming\EventType;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;

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
                $this->reply .= Scalar::string($event->data['text'] ?? null);
                break;
            case EventType::ToolCall:
                $tool = Scalar::string($event->data['tool'] ?? null);
                $input = Scalar::keyed($event->data['input'] ?? null);
                $this->toolCalls[] = ['tool' => $tool, 'input' => $input];
                if (SkillCapability::TOOL === $tool && isset($input['skill_name'])) {
                    $this->skillsLoaded[] = Scalar::string($input['skill_name']);
                }
                break;
            case EventType::ToolResult:
                $this->toolResults[] = [
                    'tool' => Scalar::string($event->data['tool'] ?? null),
                    'status' => Scalar::string($event->data['status'] ?? null),
                    'reason' => Scalar::nullableString($event->data['reason'] ?? null),
                ];
                break;
            case EventType::Ui:
                $this->components[] = Scalar::string($event->data['component'] ?? null);
                break;
            case EventType::TurnComplete:
                $this->stopReason = Scalar::nullableString($event->data['stop_reason'] ?? null);
                $this->usage = array_map(Scalar::int(...), Scalar::keyed($event->data['usage'] ?? null));
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
