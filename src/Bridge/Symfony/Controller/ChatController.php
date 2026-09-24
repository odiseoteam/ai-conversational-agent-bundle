<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Controller;

use Odiseo\AiConversationalAgentBundle\Agent\TurnRunner;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Session\SessionResolver;
use Odiseo\AiConversationalAgentBundle\Provider\AuthenticationException;
use Odiseo\AiConversationalAgentBundle\Streaming\SseEncoder;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * One turn as Server-Sent Events. The record is mutated while the turn runs and written on
 * kernel.terminate, which fires once the stream has ended.
 */
final class ChatController
{
    public function __construct(
        private readonly SessionResolver $sessions,
        private readonly TurnRunner $turns,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $perIp,
        private readonly RateLimiterFactoryInterface $perSession,
    ) {
    }

    public function chat(Request $request): Response
    {
        $record = $this->sessions->resolve($request);
        if (!$this->perIp->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()
            || !$this->perSession->create($record->sessionId)->consume()->isAccepted()) {
            return ApiError::json('too_many_messages', 429);
        }

        $body = $request->toArray();
        $message = trim((string) ($body['message'] ?? ''));
        if ('' === $message) {
            return ApiError::json('empty_message', 400);
        }

        $surface = \is_array($body['page'] ?? null) ? $body['page'] : [];
        $session = $this->sessions->context($record, $surface);

        $response = new StreamedResponse(function () use ($record, $session, $message): void {
            // An open output buffer (the SAPI's output_buffering) holds the events until the
            // turn ends; it is closed before the first one. On the CLI the buffers belong to the
            // caller (a test client capturing the stream).
            while (\PHP_SAPI !== 'cli' && ob_get_level() > 0) {
                ob_end_flush();
            }

            try {
                foreach ($this->turns->run($record, $session, $message) as $event) {
                    $this->write(SseEncoder::encode($event));
                }
            } catch (AuthenticationException $failed) {
                $this->logger->error('the model credential was rejected', ['exception' => $failed]);
                $this->write(SseEncoder::encode(ApiError::event('not_configured')));
            } catch (\Throwable $failed) {
                $this->logger->error('the agent turn failed', ['exception' => $failed]);
                $this->write(SseEncoder::encode(ApiError::event('turn_failed')));
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function write(string $frame): void
    {
        echo $frame;
        flush();
    }
}
