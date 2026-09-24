<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests;

use Odiseo\AiConversationalAgentBundle\Gate\ProvenanceGate;
use Odiseo\AiConversationalAgentBundle\Grounding\GroundingResolver;
use Odiseo\AiConversationalAgentBundle\Grounding\GroundingRule;
use Odiseo\AiConversationalAgentBundle\Grounding\Matcher;
use Odiseo\AiConversationalAgentBundle\Session\SeenRecord;
use Odiseo\AiConversationalAgentBundle\Session\TurnState;
use PHPUnit\Framework\TestCase;

/** The lexicon matchers, the rule precedence and the provenance gate the rules lean on. */
final class GroundingTest extends TestCase
{
    public function testATermMatchesAsAWholeWordIgnoringCaseAndAccents(): void
    {
        self::assertTrue(Matcher::matchesAny('¿Cuál es la política de DEVOLUCIÓN?', ['devolución']));
        self::assertFalse(Matcher::matchesAny('devolucionesX', ['devolución']));
        self::assertTrue(Matcher::matchesAny('can I return it', ['?', 'return']));
    }

    public function testATermNeedsACueAndAnEmptyLexiconNeverFires(): void
    {
        self::assertTrue(Matcher::matchesTermsAndCues('how do returns work', ['returns'], ['how']));
        self::assertFalse(Matcher::matchesTermsAndCues('returns', ['returns'], ['how']));
        self::assertFalse(Matcher::matchesTermsAndCues('how do returns work', [], ['how']));
        self::assertTrue(Matcher::matchesTermsAndCues('how much is 20%', ['refund'], ['how'], numericLiterals: true));
    }

    public function testTheLongestTokenWins(): void
    {
        $patterns = ['\b[a-z]+_[a-z]+\b', '\b[a-z]+_[a-z]+-variant-\d+\b'];

        self::assertSame('navy_tee-variant-2', Matcher::findToken('add navy_tee-variant-2 please', $patterns));
        self::assertNull(Matcher::findToken('', $patterns));
    }

    public function testTheFirstRuleThatFiresIsTheRead(): void
    {
        $never = new GroundingRule('never', 'search', static fn (): ?array => null);
        $orders = new GroundingRule('orders', 'get_orders', static fn (string $text): ?array => str_contains($text, 'order') ? [] : null);
        $policy = new GroundingRule('policy', 'search_policies', static fn (): array => ['query' => 'x']);

        $read = GroundingResolver::resolve([$never, $orders, $policy], 'where is my order', new TurnState());

        self::assertSame('get_orders', $read?->rule->tool);
        self::assertNull(GroundingResolver::resolve([$never], 'anything', new TurnState()));
    }

    public function testTheGateLetsThroughOnlyWhatAToolReturned(): void
    {
        $state = new TurnState();
        $state->remember(new SeenRecord('R-1', 'record'));

        self::assertNull(ProvenanceGate::check($state, 'R-1', 'search'));
        $held = ProvenanceGate::check($state, 'R-9', 'search the catalog');
        self::assertSame(ProvenanceGate::NAME, $held?->blocked);
        self::assertStringContainsString('search the catalog', $held->resultText);
    }
}
