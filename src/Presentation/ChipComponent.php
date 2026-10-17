<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Presentation;

/**
 * The one component that carries a turn's chips. It is named in the core because the caps and
 * the turn-closing rule treat it apart from the components that answer the request.
 */
final class ChipComponent
{
    public const TOOL = 'present_suggestions';
    public const COMPONENT = 'suggestions';

    /** The argument a ChipMode::Field component takes its chips in. */
    public const FIELD = 'suggestions';

    /** The chips tool's argument for the turn's reply, on a model that hides text written before a call. */
    public const REPLY_FIELD = 'reply';

    /** The reply a block carries in the chips tool, or '' when it is not such a call. */
    public static function replyIn(mixed $block): string
    {
        if (!\is_array($block) || 'tool_use' !== ($block['type'] ?? null) || self::TOOL !== ($block['name'] ?? null)) {
            return '';
        }
        $reply = \is_array($block['input'] ?? null) ? ($block['input'][self::REPLY_FIELD] ?? null) : null;

        return \is_string($reply) ? trim($reply) : '';
    }
}
