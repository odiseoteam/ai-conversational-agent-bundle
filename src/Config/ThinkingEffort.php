<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Config;

enum ThinkingEffort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';
}
