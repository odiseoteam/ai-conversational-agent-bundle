<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Execution;

/**
 * The one component that carries a turn's chips. It is named in the core because the caps and
 * the turn-closing rule treat it apart from the components that answer the request.
 */
final class ChipComponent
{
    public const TOOL = 'present_suggestions';
    public const COMPONENT = 'suggestions';
}
