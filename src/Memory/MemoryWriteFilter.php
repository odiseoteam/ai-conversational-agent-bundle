<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Memory;

/**
 * What may never be stored, whatever the model decides.
 *
 * The defaults are identifiers: an address, a document number, a card, a phone, an email. A
 * deployment adds its own patterns; it cannot remove these. A refused write stores nothing and
 * is not an error the person hears about — the model is told to carry on.
 */
final class MemoryWriteFilter
{
    /** @var list<string> */
    private array $patterns;

    /** @param list<string> $extraPatterns */
    public function __construct(array $extraPatterns = [])
    {
        $this->patterns = [
            // Email addresses.
            '/[\w.+-]+@[\w-]+\.[\w.-]+/u',
            // Any run of seven or more digits, spaced or not: documents, cards, phones, accounts.
            '/(?:\d[ .-]?){7,}/u',
            // Payment and credential words with a value beside them.
            '/\b(?:cvv|cvc|pin|password|passwd|contrase(?:n|ñ)a|clave|token|api[ _-]?key)\b\s*[:=]?\s*\S+/iu',
            // IBAN-shaped and CBU-shaped strings.
            '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/u',
            ...$extraPatterns,
        ];
    }

    public function allows(string $value): bool
    {
        foreach ($this->patterns as $pattern) {
            if (1 === preg_match($pattern, $value)) {
                return false;
            }
        }

        return true;
    }
}
