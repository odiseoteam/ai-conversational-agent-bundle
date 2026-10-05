<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Presentation;

/** Where the chips of a turn that ends on this component come from. */
enum ChipMode
{
    /** The chips tool, called beside the component or in a later round. */
    case Tool;

    /** The component's own `suggestions` field, so the round that renders it can end the turn. */
    case Field;

    /** Nowhere: the component carries its own next step and ends the turn without chips. */
    case None;
}
