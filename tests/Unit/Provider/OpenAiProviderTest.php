<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Provider;

use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Config\ThinkingEffort;
use Odiseo\AiConversationalAgentBundle\Provider\OpenAi\OpenAiProvider;
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
use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The adapter over the real bridge and the Responses API, with the HTTP side faked. */
final class OpenAiProviderTest extends TestCase
{
    private const REASONING = ['id' => 'rs_1', 'type' => 'reasoning', 'summary' => [], 'encrypted_content' => 'opaque'];

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    public function testTheTranscriptGoesOutAsResponsesItems(): void
    {
        $this->events($this->provider(self::sse(self::completed([]))), new TurnRequest(
            model: 'gpt-5.4-mini',
            system: [new SystemBlock('Stable rules.', true), new SystemBlock('Cart: empty.')],
            messages: [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Find a cap']]],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'reasoning', 'provider' => 'openai', 'item' => self::REASONING],
                    ['type' => 'thinking', 'provider' => 'anthropic', 'thinking' => 'Not mine.', 'signature' => 'sig'],
                    ['type' => 'text', 'text' => 'Searching.'],
                    ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'search_products', 'input' => ['query' => 'cap']],
                ]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => 'Found 2.', 'cache_hint' => true]]],
            ],
            tools: [
                new ToolSpec('search_products', 'Search.', ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]),
                new ToolSpec('get_cart', 'The cart.', ['type' => 'object', 'properties' => []]),
            ],
            toolChoice: ToolChoice::forced('search_products'),
            maxTokens: 4096,
            thinkingEffort: ThinkingEffort::Max,
            cacheKey: 'a1b2c3',
        ));

        self::assertSame([
            'stream' => true,
            'model' => 'gpt-5.4-mini',
            'input' => [
                ['role' => 'developer', 'content' => 'Stable rules.'],
                ['role' => 'developer', 'content' => 'Cart: empty.'],
                ['role' => 'user', 'content' => 'Find a cap'],
                self::REASONING,
                ['role' => 'assistant', 'content' => 'Searching.'],
                ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'search_products', 'arguments' => '{"query":"cap"}'],
                ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => 'Found 2.'],
            ],
            'max_output_tokens' => 4096,
            'store' => false,
            'tools' => [
                ['type' => 'function', 'name' => 'search_products', 'description' => 'Search.', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]],
                ['type' => 'function', 'name' => 'get_cart', 'description' => 'The cart.', 'parameters' => ['type' => 'object']],
            ],
            'tool_choice' => ['type' => 'function', 'name' => 'search_products'],
            'reasoning' => ['effort' => 'xhigh'],
            'include' => ['reasoning.encrypted_content'],
            'prompt_cache_key' => 'a1b2c3',
        ], $this->sent[0]);
    }

    public function testAModelWithoutReasoningTakesTheTemperatureInstead(): void
    {
        $this->events($this->provider(self::sse(self::completed([]))), new TurnRequest(
            model: 'gpt-4.1-mini',
            system: [new SystemBlock('Judge.')],
            messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Grade']]]],
            temperature: 0.0,
        ));

        self::assertArrayNotHasKey('reasoning', $this->sent[0]);
        self::assertSame(0.0, $this->sent[0]['temperature'] ?? null);
    }

    public function testThinkingOffIsTheLowestEffort(): void
    {
        $this->events($this->provider(self::sse(self::completed([]))), new TurnRequest(model: 'gpt-5.4-mini', system: [], messages: [['role' => 'user', 'content' => 'Hi']]));

        self::assertSame(['effort' => 'none'], $this->sent[0]['reasoning'] ?? null);
    }

    public function testAStreamBecomesEventsAndAResponseWithItsCacheReadsApart(): void
    {
        $call = ['id' => 'fc_1', 'type' => 'function_call', 'call_id' => 'call_7', 'name' => 'search_products', 'arguments' => '{"query":"cap"}', 'status' => 'completed'];
        $events = $this->events($this->provider(self::sse(
            ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'status' => 'in_progress']],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => self::REASONING],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Searching.'],
            ['type' => 'response.output_item.added', 'output_index' => 2, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_7', 'name' => 'search_products', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{"query":'],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '"cap"}'],
            ['type' => 'response.output_item.done', 'output_index' => 2, 'item' => $call],
            self::completed([self::REASONING, $call], 2424, 2304, 50),
        )), self::request());

        self::assertEquals([
            new TextChunk('Searching.'),
            new ToolCallStarted('call_7', 'search_products'),
            new ToolInputChunk('call_7', 'search_products', '{"query":'),
            new ToolInputChunk('call_7', 'search_products', '"cap"}'),
        ], \array_slice($events, 0, -1));

        $response = self::finished($events);
        self::assertSame([
            ['type' => 'reasoning', 'provider' => 'openai', 'item' => self::REASONING],
            ['type' => 'text', 'text' => 'Searching.'],
            ['type' => 'tool_use', 'id' => 'call_7', 'name' => 'search_products', 'input' => ['query' => 'cap']],
        ], $response->content);
        self::assertSame(StopReason::ToolUse, $response->stopReason);
        self::assertEquals(new Usage(120, 50, 0, 2304), $response->usage);
    }

    public function testACallCutAtTheOutputLimitIsChargedTheCap(): void
    {
        $events = $this->events($this->provider(self::sse(
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Here are'],
            ['type' => 'response.incomplete', 'response' => ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]],
        )), new TurnRequest(model: 'gpt-5.4-mini', system: [], messages: [['role' => 'user', 'content' => 'Caps?']], maxTokens: 900));

        $response = self::finished($events);
        self::assertSame(StopReason::MaxTokens, $response->stopReason);
        self::assertSame([['type' => 'text', 'text' => 'Here are']], $response->content);
        self::assertEquals(new Usage(0, 900), $response->usage);
    }

    public function testWhatEachModelCanDo(): void
    {
        $provider = $this->provider('');

        self::assertTrue($provider->capabilities('gpt-5.4-mini')->thinking);
        self::assertFalse($provider->capabilities('gpt-5.4-mini')->temperature);
        self::assertFalse($provider->capabilities('gpt-4.1-mini')->thinking);
        self::assertFalse($provider->capabilities('gpt-5.4-mini')->promptCaching, 'caching is automatic: no markers');
        self::assertFalse($provider->capabilities('gpt-5.6-luna')->textBesideToolCalls, 'its prompt says text and tool calls go together');
    }

    private function provider(string $body): OpenAiProvider
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($body): MockResponse {
            /** @var array<string, mixed> $sent */
            $sent = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true, flags: \JSON_THROW_ON_ERROR);
            $this->sent[] = $sent;

            return new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        return new OpenAiProvider(Factory::createPlatform('sk-test', $client));
    }

    /** @return list<StreamEvent> */
    private function events(OpenAiProvider $provider, TurnRequest $request): array
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

    private static function request(): TurnRequest
    {
        return new TurnRequest(model: 'gpt-5.4-mini', system: [new SystemBlock('Rules.')], messages: [['role' => 'user', 'content' => 'Caps?']]);
    }

    /**
     * @param list<array<string, mixed>> $output
     *
     * @return array<string, mixed>
     */
    private static function completed(array $output, int $input = 10, int $cached = 0, int $outputTokens = 5): array
    {
        return ['type' => 'response.completed', 'response' => [
            'id' => 'resp_1',
            'status' => 'completed',
            'output' => $output,
            'usage' => ['input_tokens' => $input, 'input_tokens_details' => ['cached_tokens' => $cached], 'output_tokens' => $outputTokens, 'total_tokens' => $input + $outputTokens],
        ]];
    }

    /** @param array<string, mixed> ...$events */
    private static function sse(array ...$events): string
    {
        $body = '';
        foreach ($events as $event) {
            $body .= \sprintf("event: %s\ndata: %s\n\n", \is_string($event['type'] ?? null) ? $event['type'] : '', json_encode($event, \JSON_THROW_ON_ERROR));
        }

        return $body;
    }
}
