<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Streaming;

/**
 * The events a turn yields to its host. A host renders what it knows and ignores the rest.
 *
 * text_delta    {text}: incremental assistant text.
 * tool_call     {tool, id, input, label?}; label is the model's few words for the person
 *               waiting (the call's `status` argument), absent on presentation calls.
 * tool_result   {tool, id, summary, is_error, status, reason?, excerpt?}; status is ok,
 *               error or blocked (a gate held the call; reason names the gate).
 * ui            {component, payload}: a validated, enriched component.
 * ui_partial    The same plus stream_id, while the call is still being generated: one frame
 *               per visible change (a title, one more entry), built by the component's
 *               partial hook. The final ui carries the same stream_id and replaces the last
 *               frame; a call that ends refused leaves no ui, so the host drops its frames.
 * progress      {message, tool?, step?}: a status line replacing the previous one.
 * state_update  {key, value}: a whole piece of vertical state after it moved.
 * turn_complete {stop_reason, usage, elapsed_ms, results_cleared}.
 * error         {message}, safe to show.
 */
enum EventType: string
{
    case TextDelta = 'text_delta';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
    case Ui = 'ui';
    case UiPartial = 'ui_partial';
    case Progress = 'progress';
    case StateUpdate = 'state_update';
    case TurnComplete = 'turn_complete';
    case Error = 'error';
}
