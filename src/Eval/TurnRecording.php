<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval;

use Odiseo\AiConversationalAgentBundle\Skill\SkillCapability;
use Odiseo\AiConversationalAgentBundle\Streaming\AgentEvent;
use Odiseo\AiConversationalAgentBundle\Streaming\EventType;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;

/**
 * What a graded case produced, across all its turns. Graders read this rather than the
 * transcript, because what is being asserted is the calls the agent made and the state they
 * left, not its wording. It round-trips through JSON, so a stored run can be graded again.
 */
final class TurnRecording
{
    /** @var list<array{tool: string, input: array<string, mixed>}> */
    public array $toolCalls = [];
    /** @var list<string> */
    public array $components = [];
    /** @var list<array{component: string, payload: array<string, mixed>}> what each component showed */
    public array $payloads = [];
    /** @var list<array<string, mixed>> the session's messages after the case, tool results included */
    public array $transcript = [];
    /** @var list<string> */
    public array $skillsLoaded = [];
    /** @var list<array{tool: string, status: string, reason: string|null}> */
    public array $toolResults = [];
    public string $reply = '';
    public ?string $stopReason = null;
    /** @var array<string, int> summed over the turns */
    public array $usage = [];
    public int $rounds = 0;
    /** @var list<int> */
    public array $turnMs = [];
    /** @var array<string, mixed> the last state_update per key */
    public array $state = [];
    /** @var list<array{key: string, value: string, category: string}> the subject's facts after the case */
    public array $memory = [];
    /** @var array<string, mixed> what the host's environment read back after the case */
    public array $endState = [];
    /** Spend charged to the case's session: its turns and its memory extraction. */
    public float $costUsd = 0.0;
    /** @var array<string, mixed>|null the judge's verdict, when the case has a rubric */
    public ?array $verdict = null;

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
                $component = Scalar::string($event->data['component'] ?? null);
                $this->components[] = $component;
                $this->payloads[] = ['component' => $component, 'payload' => Scalar::keyed($event->data['payload'] ?? null)];
                break;
            case EventType::StateUpdate:
                $this->state[Scalar::string($event->data['key'] ?? null)] = $event->data['value'] ?? null;
                break;
            case EventType::TurnComplete:
                $this->stopReason = Scalar::nullableString($event->data['stop_reason'] ?? null);
                foreach (Scalar::keyed($event->data['usage'] ?? null) as $key => $tokens) {
                    $this->usage[$key] = ($this->usage[$key] ?? 0) + Scalar::int($tokens);
                }
                $this->rounds += Scalar::int($event->data['rounds'] ?? null);
                $this->turnMs[] = Scalar::int($event->data['elapsed_ms'] ?? null);
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'toolCalls' => $this->toolCalls,
            'components' => $this->components,
            'payloads' => $this->payloads,
            'transcript' => $this->transcript,
            'skillsLoaded' => $this->skillsLoaded,
            'toolResults' => $this->toolResults,
            'reply' => $this->reply,
            'stopReason' => $this->stopReason,
            'usage' => $this->usage,
            'rounds' => $this->rounds,
            'turnMs' => $this->turnMs,
            'state' => $this->state,
            'memory' => $this->memory,
            'endState' => $this->endState,
            'costUsd' => $this->costUsd,
            'verdict' => $this->verdict,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $recording = new self();
        foreach (Scalar::rows($data['toolCalls'] ?? null) as $call) {
            $recording->toolCalls[] = ['tool' => Scalar::string($call['tool'] ?? null), 'input' => Scalar::keyed($call['input'] ?? null)];
        }
        $recording->components = Scalar::strings($data['components'] ?? null);
        foreach (Scalar::rows($data['payloads'] ?? null) as $shown) {
            $recording->payloads[] = ['component' => Scalar::string($shown['component'] ?? null), 'payload' => Scalar::keyed($shown['payload'] ?? null)];
        }
        $recording->transcript = Scalar::rows($data['transcript'] ?? null);
        $recording->skillsLoaded = Scalar::strings($data['skillsLoaded'] ?? null);
        foreach (Scalar::rows($data['toolResults'] ?? null) as $result) {
            $recording->toolResults[] = [
                'tool' => Scalar::string($result['tool'] ?? null),
                'status' => Scalar::string($result['status'] ?? null),
                'reason' => Scalar::nullableString($result['reason'] ?? null),
            ];
        }
        $recording->reply = Scalar::string($data['reply'] ?? null);
        $recording->stopReason = Scalar::nullableString($data['stopReason'] ?? null);
        $recording->usage = array_map(Scalar::int(...), Scalar::keyed($data['usage'] ?? null));
        $recording->rounds = Scalar::int($data['rounds'] ?? null);
        $recording->turnMs = array_values(array_map(Scalar::int(...), \is_array($data['turnMs'] ?? null) ? $data['turnMs'] : []));
        $recording->state = Scalar::keyed($data['state'] ?? null);
        foreach (Scalar::rows($data['memory'] ?? null) as $fact) {
            $recording->memory[] = [
                'key' => Scalar::string($fact['key'] ?? null),
                'value' => Scalar::string($fact['value'] ?? null),
                'category' => Scalar::string($fact['category'] ?? null),
            ];
        }
        $recording->endState = Scalar::keyed($data['endState'] ?? null);
        $recording->costUsd = Scalar::float($data['costUsd'] ?? null);
        $recording->verdict = \is_array($data['verdict'] ?? null) ? Scalar::keyed($data['verdict']) : null;

        return $recording;
    }
}
