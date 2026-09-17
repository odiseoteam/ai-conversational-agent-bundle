<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Streaming;

final class SseEncoder
{
    /**
     * One Server-Sent Events frame: `event:` is the event type, `data:` its JSON payload on
     * one line, then a blank line.
     */
    public static function encode(AgentEvent $event): string
    {
        $data = json_encode($event->data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return 'event: '.$event->type->value."\ndata: ".$data."\n\n";
    }
}
