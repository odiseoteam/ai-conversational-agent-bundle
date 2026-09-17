<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Execution;

/** What one turn has spent of the caps that are counted per turn. */
final class TurnScope
{
    private int $componentsPresented = 0;

    private bool $chipsSent = false;

    public function componentsPresented(): int
    {
        return $this->componentsPresented;
    }

    public function countComponent(bool $isChips): void
    {
        if ($isChips) {
            $this->chipsSent = true;

            return;
        }

        ++$this->componentsPresented;
    }

    public function chipsSent(): bool
    {
        return $this->chipsSent;
    }
}
