<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Capability;

enum PromptSection: string
{
    case HowYouWork = 'how_you_work';
    case Tools = 'tools';
    case Presentation = 'presentation';
    case Boundaries = 'boundaries';

    /** Render order of the sections in the static prompt. */
    public function order(): int
    {
        return match ($this) {
            self::HowYouWork => 0,
            self::Tools => 1,
            self::Presentation => 2,
            self::Boundaries => 3,
        };
    }
}
