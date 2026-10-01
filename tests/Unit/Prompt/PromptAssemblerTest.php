<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Prompt;

use Odiseo\AiConversationalAgentBundle\Prompt\PromptAssembler;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Dig;
use PHPUnit\Framework\TestCase;

final class PromptAssemblerTest extends TestCase
{
    public function testTheBreakpointSitsOnTheStaticBlockOnly(): void
    {
        $blocks = PromptAssembler::systemBlocks('static', 'per request');

        self::assertTrue($blocks[0]->cacheHint);
        self::assertFalse($blocks[1]->cacheHint);
    }

    public function testTheClockRendersTheHourNotTheMinute(): void
    {
        $now = new \DateTimeImmutable('2026-05-30 10:37:12', new \DateTimeZone('-03:00'));

        self::assertSame('2026-05-30T10:00-03:00', PromptAssembler::contextClock($now));
    }

    public function testTheRollingMarkerLandsOnTheNewestBlock(): void
    {
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'hello']]],
        ];

        $request = PromptAssembler::requestMessages($messages);

        self::assertTrue(Dig::value($request, 1, 'content', 0, 'cache_hint'));
        self::assertArrayNotHasKey('cache_hint', Dig::array($messages, 1, 'content', 0), 'the stored history must not be touched');
    }

    public function testASingleMessageGetsNoMarker(): void
    {
        $request = PromptAssembler::requestMessages([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]);

        self::assertArrayNotHasKey('cache_hint', Dig::array($request, 0, 'content', 0));
    }

    public function testAForcedRoundGetsNoMarker(): void
    {
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'hello']]],
        ];

        $request = PromptAssembler::requestMessages($messages, rollingBreakpoint: false);

        self::assertArrayNotHasKey('cache_hint', Dig::array($request, 1, 'content', 0));
    }

    public function testAnEarlierMarkerIsStripped(): void
    {
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi', 'cache_hint' => true]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'hello']]],
        ];

        $request = PromptAssembler::requestMessages($messages);

        self::assertArrayNotHasKey('cache_hint', Dig::array($request, 0, 'content', 0));
        self::assertTrue(Dig::value($request, 1, 'content', 0, 'cache_hint'));
    }

    public function testConsecutiveUserMessagesAreMergedToolResultsFirst(): void
    {
        $messages = [
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'listo']]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'tu-1', 'content' => 'ok']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'otra cosa']]],
        ];

        $request = PromptAssembler::requestMessages($messages);

        self::assertCount(2, $request);
        self::assertSame('tool_result', Dig::value($request, 1, 'content', 0, 'type'));
        self::assertSame('text', Dig::value($request, 1, 'content', 1, 'type'));
    }
}
