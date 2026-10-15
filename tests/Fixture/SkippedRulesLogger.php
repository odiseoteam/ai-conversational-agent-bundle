<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture;

use Psr\Log\AbstractLogger;

/** Keeps each rule the RuleBook left out as "id: reason". */
final class SkippedRulesLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $skipped = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $id = $context['id'] ?? '';
        $reason = $context['reason'] ?? '';
        $this->skipped[] = (\is_string($id) ? $id : '').': '.(\is_string($reason) ? $reason : '');
    }
}
