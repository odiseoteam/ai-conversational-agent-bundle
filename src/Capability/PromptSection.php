<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Capability;

enum PromptSection: string
{
    case HowYouWork = 'how_you_work';
    case Tools = 'tools';
    case Presentation = 'presentation';
    /** How the vertical wants requests handled, beside the organisation's rules. */
    case Guidance = 'guidance';
    case Boundaries = 'boundaries';

    /** Render order of the sections in the static prompt. */
    public function order(): int
    {
        return match ($this) {
            self::HowYouWork => 0,
            self::Tools => 1,
            self::Presentation => 2,
            self::Guidance => 3,
            self::Boundaries => 4,
        };
    }
}
