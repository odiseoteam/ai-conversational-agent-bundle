<?php

declare(strict_types=1);

namespace Odiseo\AiAgentBundle\Presentation;

/**
 * Raised by a validator or an enrich hook when the call cannot render. $gate names the gate
 * that held it, which makes the result a held call; without a gate it is an error.
 */
final class PresentationRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $gate = null)
    {
        parent::__construct($message);
    }
}
