<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Provider\Response;

/** Why a model call ended, in this product's words whatever the provider reported. */
enum StopReason: string
{
    case EndTurn = 'end_turn';
    case ToolUse = 'tool_use';
    /** Cut at the output token limit: the content is incomplete. */
    case MaxTokens = 'max_tokens';
    case Refusal = 'refusal';
    case StopSequence = 'stop_sequence';
    case Other = 'other';
}
