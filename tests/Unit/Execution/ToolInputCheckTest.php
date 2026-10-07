<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Execution;

use Odiseo\AiConversationalAgentBundle\Execution\ToolInputCheck;
use PHPUnit\Framework\TestCase;

final class ToolInputCheckTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string'],
            'filters' => [
                'type' => 'object',
                'properties' => [
                    'category' => ['type' => 'string'],
                    'attributes' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    'sort' => ['type' => 'string', 'enum' => ['relevance', 'height_desc']],
                ],
                'additionalProperties' => false,
            ],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['new', 'sale']]],
            'limit' => ['type' => 'integer'],
        ],
        'required' => ['query'],
        'additionalProperties' => false,
    ];

    public function testValidArgumentsHaveNoProblems(): void
    {
        self::assertSame([], ToolInputCheck::problems(self::SCHEMA, [
            'query' => 'tree',
            'filters' => ['category' => 'Christmas', 'attributes' => ['Color' => 'Green'], 'sort' => 'height_desc'],
            'tags' => ['sale'],
            'limit' => '8',
        ]));
    }

    public function testAnEmptyObjectIsNotAList(): void
    {
        self::assertSame([], ToolInputCheck::problems(self::SCHEMA, ['query' => 'tree', 'filters' => []]));
    }

    public function testAnArgumentInTheWrongPlaceSaysWhereItGoes(): void
    {
        self::assertSame(
            ['`sort` is not a parameter here (allowed: query, filters, tags, limit); it goes in `filters.sort`'],
            ToolInputCheck::problems(self::SCHEMA, ['query' => 'tree', 'sort' => 'height_desc']),
        );
    }

    public function testAnArgumentTheSchemaNeverDeclaresListsTheAllowedOnes(): void
    {
        self::assertSame(
            ['`filters.order` is not a parameter here (allowed: category, attributes, sort)'],
            ToolInputCheck::problems(self::SCHEMA, ['query' => 'tree', 'filters' => ['order' => 'tallest']]),
        );
    }

    public function testAnObjectThatAllowsMoreTakesAnyKey(): void
    {
        $schema = ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]];

        self::assertSame([], ToolInputCheck::problems($schema, ['query' => 'tree', 'extra' => 1]));
    }

    public function testAMissingRequiredArgumentIsReported(): void
    {
        self::assertSame(['`query` is required'], ToolInputCheck::problems(self::SCHEMA, ['limit' => 3]));
    }

    public function testAValueOutsideItsEnumIsReported(): void
    {
        self::assertSame(
            ['`filters.sort` must be one of: relevance, height_desc', '`tags[1]` must be one of: new, sale'],
            ToolInputCheck::problems(self::SCHEMA, ['query' => 'tree', 'filters' => ['sort' => 'biggest'], 'tags' => ['new', 'cheap']]),
        );
    }
}
