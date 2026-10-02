<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Provider;

use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Config\ThinkingEffort;
use Odiseo\AiConversationalAgentBundle\Provider\Anthropic\AnthropicProvider;
use Odiseo\AiConversationalAgentBundle\Provider\Request\SystemBlock;
use Odiseo\AiConversationalAgentBundle\Provider\Request\ToolChoice;
use Odiseo\AiConversationalAgentBundle\Provider\Request\TurnRequest;
use Odiseo\AiConversationalAgentBundle\Provider\Response\ProviderResponse;
use Odiseo\AiConversationalAgentBundle\Provider\Response\StopReason;
use Odiseo\AiConversationalAgentBundle\Provider\Response\Usage;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\StreamEvent;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TextChunk;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\ToolCallStarted;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\ToolInputChunk;
use Odiseo\AiConversationalAgentBundle\Provider\Stream\TurnFinished;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The adapter over the real bridge, with the HTTP side faked: what it sends and what it reads. */
final class AnthropicProviderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    public function testTheRequestCarriesOnlyThisProductsCacheMarkers(): void
    {
        $this->events($this->provider(self::sse(self::start(3), self::stop('end_turn'))), new TurnRequest(
            model: 'claude-haiku-4-5',
            system: [new SystemBlock('Stable rules.', true), new SystemBlock('Cart: empty.')],
            messages: [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Find a cap']]],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'thinking', 'provider' => 'anthropic', 'thinking' => 'Search first.', 'signature' => 'sig-1'],
                    ['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'search_products', 'input' => ['query' => 'cap']],
                ]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'tu-1', 'content' => 'Found 2.', 'cache_hint' => true]]],
            ],
            tools: [
                new ToolSpec('search_products', 'Search.', ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]),
                new ToolSpec('get_cart', 'The cart.', ['type' => 'object', 'properties' => []]),
            ],
            toolChoice: ToolChoice::forced('search_products'),
            maxTokens: 1024,
            thinkingEffort: ThinkingEffort::Low,
        ));

        self::assertSame([
            'max_tokens' => 1024,
            'stream' => true,
            'model' => 'claude-haiku-4-5',
            'system' => [
                ['type' => 'text', 'text' => 'Stable rules.', 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => 'Cart: empty.'],
            ],
            'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Find a cap']]],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'thinking', 'thinking' => 'Search first.', 'signature' => 'sig-1'],
                    ['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'search_products', 'input' => ['query' => 'cap']],
                ]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'tu-1', 'content' => 'Found 2.', 'cache_control' => ['type' => 'ephemeral']]]],
            ],
            'tools' => [
                ['name' => 'search_products', 'description' => 'Search.', 'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]],
                // An empty `properties` is left out: OpenAI rejects `[]` and `{}` cannot be serialized.
                ['name' => 'get_cart', 'description' => 'The cart.', 'input_schema' => ['type' => 'object'], 'cache_control' => ['type' => 'ephemeral']],
            ],
            'tool_choice' => ['type' => 'tool', 'name' => 'search_products'],
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => 'low'],
        ], $this->sent[0]);
    }

    public function testACallWithoutThinkingOrToolsSendsTheTemperature(): void
    {
        $this->events($this->provider(self::sse(self::start(3), self::stop('end_turn'))), new TurnRequest(
            model: 'claude-haiku-4-5',
            system: [new SystemBlock('Judge.')],
            messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Grade']]]],
            maxTokens: 512,
            temperature: 0.0,
            cacheTools: false,
        ));

        self::assertSame([
            'max_tokens' => 512,
            'stream' => true,
            'model' => 'claude-haiku-4-5',
            'system' => [['type' => 'text', 'text' => 'Judge.']],
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Grade']]]],
            'temperature' => 0.0,
            'thinking' => ['type' => 'disabled'],
        ], $this->sent[0]);
    }

    public function testAnotherProvidersReasoningIsLeftOut(): void
    {
        $this->events($this->provider(self::sse(self::start(3), self::stop('end_turn'))), self::request([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ['role' => 'assistant', 'content' => [
                ['type' => 'reasoning', 'provider' => 'openai', 'encrypted_content' => 'opaque'],
                ['type' => 'thinking', 'thinking' => 'Before the tag.', 'signature' => 'sig-0'],
                ['type' => 'text', 'text' => 'Hello.'],
            ]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Caps?']]],
        ]));

        /** @var list<array{content: list<array<string, mixed>>}> $messages */
        $messages = $this->sent[0]['messages'];
        self::assertSame([
            ['type' => 'thinking', 'thinking' => 'Before the tag.', 'signature' => 'sig-0'],
            ['type' => 'text', 'text' => 'Hello.'],
        ], $messages[1]['content']);
    }

    public function testAStreamBecomesEventsAndAResponse(): void
    {
        $events = $this->events($this->provider(self::sse(
            self::start(12, cacheRead: 900, cacheWrite: 40),
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Look it up.']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig-9']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Searching.']],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'tu-7', 'name' => 'search_products', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":']],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"cap"}']],
            ['type' => 'content_block_stop', 'index' => 2],
            ...self::stop('tool_use', 30),
        )), self::request());

        self::assertEquals([
            new TextChunk('Searching.'),
            new ToolCallStarted('tu-7', 'search_products'),
            new ToolInputChunk('tu-7', 'search_products', '{"query":'),
            new ToolInputChunk('tu-7', 'search_products', '"cap"}'),
        ], \array_slice($events, 0, -1));

        $response = self::finished($events);
        self::assertSame([
            ['type' => 'thinking', 'provider' => 'anthropic', 'thinking' => 'Look it up.', 'signature' => 'sig-9'],
            ['type' => 'text', 'text' => 'Searching.'],
            ['type' => 'tool_use', 'id' => 'tu-7', 'name' => 'search_products', 'input' => ['query' => 'cap']],
        ], $response->content);
        self::assertSame(['query' => 'cap'], $response->toolUses[0]->input ?? null);
        self::assertSame(StopReason::ToolUse, $response->stopReason);
        self::assertEquals(new Usage(12, 30, 40, 900), $response->usage);
    }

    public function testACallCutAtTheOutputLimitIsAResponseThatIsCharged(): void
    {
        $events = $this->events($this->provider(self::sse(
            self::start(20, cacheRead: 500),
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Here are']],
            ['type' => 'content_block_stop', 'index' => 0],
            ...self::stop('max_tokens', 1024),
        )), self::request());

        $response = self::finished($events);
        self::assertSame(StopReason::MaxTokens, $response->stopReason);
        self::assertSame([['type' => 'text', 'text' => 'Here are']], $response->content);
        self::assertEquals(new Usage(20, 1024, 0, 500), $response->usage);
    }

    private function provider(string $body): AnthropicProvider
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($body): MockResponse {
            /** @var array<string, mixed> $sent */
            $sent = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true, flags: \JSON_THROW_ON_ERROR);
            $this->sent[] = $sent;

            return new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        return new AnthropicProvider(Factory::createPlatform('key', $client, cacheRetention: 'none'));
    }

    /** @return list<StreamEvent> */
    private function events(AnthropicProvider $provider, TurnRequest $request): array
    {
        $events = [];
        foreach ($provider->stream($request) as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /** @param list<StreamEvent> $events */
    private static function finished(array $events): ProviderResponse
    {
        $last = $events[\count($events) - 1] ?? null;
        self::assertInstanceOf(TurnFinished::class, $last);

        return $last->response;
    }

    /** @param list<array<string, mixed>> $messages */
    private static function request(array $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Caps?']]]]): TurnRequest
    {
        return new TurnRequest(model: 'claude-haiku-4-5', system: [new SystemBlock('Rules.')], messages: $messages);
    }

    /** @return array<string, mixed> */
    private static function start(int $input, int $cacheRead = 0, int $cacheWrite = 0): array
    {
        return ['type' => 'message_start', 'message' => ['id' => 'msg-1', 'type' => 'message', 'role' => 'assistant', 'content' => [], 'usage' => [
            'input_tokens' => $input,
            'cache_creation_input_tokens' => $cacheWrite,
            'cache_read_input_tokens' => $cacheRead,
            'output_tokens' => 1,
        ]]];
    }

    /** @return list<array<string, mixed>> */
    private static function stop(string $reason, int $output = 5): array
    {
        return [
            ['type' => 'message_delta', 'delta' => ['stop_reason' => $reason], 'usage' => ['output_tokens' => $output]],
            ['type' => 'message_stop'],
        ];
    }

    /** @param array<string, mixed>|list<array<string, mixed>> ...$events */
    private static function sse(array ...$events): string
    {
        $body = '';
        foreach ($events as $event) {
            /** @var list<array<string, mixed>> $ones */
            $ones = array_is_list($event) ? $event : [$event];
            foreach ($ones as $one) {
                $body .= \sprintf("event: %s\ndata: %s\n\n", \is_string($one['type'] ?? null) ? $one['type'] : '', json_encode($one, \JSON_THROW_ON_ERROR));
            }
        }

        return $body;
    }
}
