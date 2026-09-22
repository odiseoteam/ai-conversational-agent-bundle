<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Handoff;

use Odiseo\AiConversationalAgentBundle\Capability\Capability;
use Odiseo\AiConversationalAgentBundle\Capability\PromptFragment;
use Odiseo\AiConversationalAgentBundle\Capability\PromptSection;
use Odiseo\AiConversationalAgentBundle\Capability\ToolContext;
use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Presentation\EnrichmentContext;
use Odiseo\AiConversationalAgentBundle\Presentation\PayloadGuard;
use Odiseo\AiConversationalAgentBundle\Presentation\PresentationComponent;
use Odiseo\AiConversationalAgentBundle\Presentation\PresentationRefused;
use Odiseo\AiConversationalAgentBundle\Session\SessionStore;
use Odiseo\AiConversationalAgentBundle\Streaming\AgentEvent;
use Odiseo\AiConversationalAgentBundle\Streaming\ToolOutcome;

/**
 * Handing the conversation to a person. The model opens a request with a reason and a
 * summary; the channel takes it; the state remembers it so the card can show it and a second
 * call in the same session does not open another. Nothing the model writes reaches the
 * channel unsanitized, and a request never alters anything on its own.
 *
 * Always registered; the enabled flag is read at runtime so a deployment can flip it from an
 * environment variable.
 */
final class HandoffCapability implements Capability
{
    public const REQUEST = 'request_human_help';
    public const PRESENT = 'present_handoff';
    public const COMPONENT = 'handoff';
    public const STATE_KEY = 'handoff';

    /** Gate names on a held call. */
    public const CONTACT_GATE = 'handoff_contact';
    public const STATE_GATE = 'handoff_state';

    private const SUMMARY_MAX_CHARS = 600;

    public function __construct(
        private readonly HandoffChannel $channel,
        private readonly HandoffStore $store,
        private readonly SessionStore $sessions,
        private readonly HandoffSettings $settings = new HandoffSettings(),
    ) {
    }

    public function name(): string
    {
        return 'core.handoff';
    }

    public function tools(): array
    {
        if (!$this->settings->enabled) {
            return [];
        }

        return [
            new ToolSpec(
                self::REQUEST,
                'Hand the conversation to a person at the organisation. Use it when the customer asks for a human, when what they need is outside what you cover, when it takes an action only the organisation can do (change, cancel, refund), or when you could not settle it. Opens one request per conversation; nothing else happens on its own.',
                [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'enum' => HandoffReason::values(), 'description' => 'Why it is handed over.'],
                        'summary' => ['type' => 'string', 'maxLength' => self::SUMMARY_MAX_CHARS, 'description' => 'What the person needs, with the facts already gathered (order number, item, dates), in a few sentences the team can act on.'],
                        'contact' => ['type' => 'string', 'maxLength' => 120, 'description' => 'An email address or phone number the customer gave for the reply. Required for a visitor who is not signed in.'],
                    ],
                    'required' => ['reason', 'summary'],
                    'additionalProperties' => false,
                ],
            ),
            new ToolSpec(
                self::PRESENT,
                'Show the card for the request opened in this conversation: its reference and what happens next. Call it right after request_human_help went through.',
                [
                    'type' => 'object',
                    'properties' => [
                        'note' => ['type' => 'string', 'maxLength' => 200, 'description' => 'One line for the customer about what to expect or what to do meanwhile.'],
                    ],
                    'additionalProperties' => false,
                ],
                wantsStatusLine: false,
            ),
        ];
    }

    public function promptFragments(): array
    {
        if (!$this->settings->enabled) {
            return [];
        }

        return [new PromptFragment(
            PromptSection::Boundaries,
            '- When the person asks for a human, when the request is outside your scope, when it needs an action only the organisation can take, or after two attempts that did not settle it, hand it over with request_human_help: gather the facts first, ask a visitor who is not signed in for an email or phone number, then open the request and show it with present_handoff. Say what will happen next in the words the tool returned; do not promise a time, and make clear nothing has been changed yet. One request per conversation: if one is already open, say it is on its way.',
            priority: 40,
        )];
    }

    public function groundingRules(): array
    {
        return [];
    }

    public function components(): array
    {
        if (!$this->settings->enabled) {
            return [];
        }

        return [new PresentationComponent(self::PRESENT, self::COMPONENT, $this->validate(...), $this->enrich(...))];
    }

    public function execute(string $tool, array $input, ToolContext $context): ToolOutcome
    {
        return match ($tool) {
            self::REQUEST => $this->request($input, $context),
            default => ToolOutcome::error(\sprintf('Unknown tool: %s', $tool)),
        };
    }

    /** @param array<string, mixed> $input */
    private function request(array $input, ToolContext $context): ToolOutcome
    {
        $open = $context->state->get(self::STATE_KEY);
        if (\is_array($open) && HandoffRecord::OPEN === ($open['status'] ?? null)) {
            return new ToolOutcome(\sprintf('A request is already open in this conversation (reference %s). Tell the customer it is on its way; do not open another.', (string) $open['reference']));
        }

        $reason = HandoffReason::tryFrom((string) ($input['reason'] ?? '')) ?? HandoffReason::Unresolved;
        $summary = $context->sanitize($input['summary'] ?? '', self::SUMMARY_MAX_CHARS);
        if ('' === $summary) {
            return ToolOutcome::error('summary is required: say what the customer needs and the facts gathered so far.');
        }

        $rawContact = trim($context->sanitize($input['contact'] ?? '', 120));
        $contact = Contact::normalize($rawContact);
        if ('' !== $rawContact && null === $contact) {
            return ToolOutcome::held(self::CONTACT_GATE, 'contact has to be an email address or a phone number. Ask the customer for one and call again.');
        }
        if (null === $contact && $context->session->guest && $this->settings->contactRequiredForGuests) {
            return ToolOutcome::held(self::CONTACT_GATE, 'This visitor is not signed in and left no way to be reached. Ask for an email address or phone number first, then call again with it in contact.');
        }

        $request = new HandoffRequest(
            reference: self::reference(),
            sessionId: $context->session->sessionId,
            principalId: $context->session->principalId,
            guest: $context->session->guest,
            reason: $reason,
            summary: $summary,
            contact: $contact,
            excerpt: TranscriptExcerpt::of($this->sessions->readMessages($context->session->sessionId), $this->settings->excerptMessages),
            surface: $context->session->surface,
            requestedAt: $context->session->localNow() ?? new \DateTimeImmutable(),
        );

        $ticket = $this->channel->open($request);
        $record = HandoffRecord::open($request, $ticket);
        $this->store->save($record);

        $state = [
            'reference' => $record->reference,
            'status' => $record->status,
            'channel' => $record->channel,
            'mode' => $record->mode,
            'contact' => $record->contact,
            'expectation' => $ticket->expectation ?? $this->settings->expectation,
            'requested_at' => $record->createdAt->format(\DATE_ATOM),
        ];
        $context->state->set(self::STATE_KEY, $state);

        return new ToolOutcome(
            json_encode([
                'reference' => $state['reference'],
                'expectation' => $state['expectation'],
                'note' => 'The request is open. Call present_handoff, then tell the customer in one line what happens next; nothing has been changed on their account or order.',
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            [AgentEvent::stateUpdate(self::STATE_KEY, $state)],
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        return ['note' => PayloadGuard::optionalText($input['note'] ?? null, 200)];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function enrich(array $payload, EnrichmentContext $context): array
    {
        $open = $context->tools->state->get(self::STATE_KEY);
        if (!\is_array($open)) {
            throw new PresentationRefused(\sprintf('no request was opened in this conversation; call %s first, then %s.', self::REQUEST, self::PRESENT), self::STATE_GATE);
        }

        return array_filter($open + ['note' => $payload['note']], static fn (mixed $v): bool => null !== $v);
    }

    /** Short, readable, no ambiguous glyphs: what a person quotes back by phone. */
    private static function reference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; ++$i) {
            $code .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return 'H-'.$code;
    }
}
