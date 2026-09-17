<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Memory;

use Odiseo\AiAgentBundle\Capability\Capability;
use Odiseo\AiAgentBundle\Capability\PromptFragment;
use Odiseo\AiAgentBundle\Capability\PromptSection;
use Odiseo\AiAgentBundle\Capability\ToolContext;
use Odiseo\AiAgentBundle\Capability\ToolSpec;
use Odiseo\AiAgentBundle\Streaming\ToolOutcome;

/**
 * Remembering across sessions. The tools stay registered whether or not memory is on: turning
 * it off changes what they do, not whether the model can be asked to remember something, so a
 * request to remember is always answered honestly.
 */
final class MemoryCapability implements Capability
{
    public function __construct(private readonly MemoryRuntime $memory)
    {
    }

    public function name(): string
    {
        return 'core.memory';
    }

    public function tools(): array
    {
        return [
            new ToolSpec(
                'save_memory',
                'Remember one durable fact about this person for future conversations: a standing preference, a constraint, or a fact about their situation that will still be true next time. Not for anything about this conversation alone, and never for an identifier, a document number or a contact detail.',
                [
                    'type' => 'object',
                    'properties' => [
                        'key' => [
                            'type' => 'string',
                            'maxLength' => 64,
                            'description' => 'A short, stable name for the fact, so a later save on the same subject replaces it.',
                        ],
                        'value' => [
                            'type' => 'string',
                            'maxLength' => 200,
                            'description' => 'The fact in one plain sentence.',
                        ],
                        'category' => [
                            'type' => 'string',
                            'enum' => ['preference', 'constraint', 'context'],
                            'description' => 'constraint for a hard limit, preference for a leaning, context for their situation.',
                        ],
                    ],
                    'required' => ['key', 'value'],
                    'additionalProperties' => false,
                ],
            ),
            new ToolSpec(
                'recall_memories',
                'Search what is already remembered about this person for something not already in the session context: an older preference, a past request, a recurring need. Use it only when an older fact would change your answer.',
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'What to look for, in the words the fact would be stored in.',
                        ],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ),
        ];
    }

    public function promptFragments(): array
    {
        return [new PromptFragment(
            PromptSection::HowYouWork,
            '- A personal fact that is not in the Session context block or in a recall result is not remembered: say you do not have it rather than guessing. Save something only when it will still be true next time, and never save an identifier, a document number or a contact detail.',
            priority: 50,
        )];
    }

    public function groundingRules(): array
    {
        return [];
    }

    public function components(): array
    {
        return [];
    }

    public function execute(string $tool, array $input, ToolContext $context): ToolOutcome
    {
        return match ($tool) {
            'save_memory' => $this->memory->save($context->session->principalId, $context->session->sessionTag(), $input),
            'recall_memories' => $this->memory->recall($context->session->principalId, $input),
            default => ToolOutcome::error(\sprintf('Unknown tool: %s', $tool)),
        };
    }
}
