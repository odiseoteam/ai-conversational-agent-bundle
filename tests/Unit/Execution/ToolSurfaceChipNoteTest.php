<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Unit\Execution;

use Odiseo\AiConversationalAgentBundle\Capability\CapabilityRegistry;
use Odiseo\AiConversationalAgentBundle\Capability\ToolSpec;
use Odiseo\AiConversationalAgentBundle\Execution\ToolSurface;
use Odiseo\AiConversationalAgentBundle\Presentation\ChipMode;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\DirectoryCapability;
use PHPUnit\Framework\TestCase;

/** A card's chip note follows its tool's description, so the chips skip what it shows. */
final class ToolSurfaceChipNoteTest extends TestCase
{
    public function testTheNoteFollowsTheToolDescription(): void
    {
        $tool = $this->tool(new DirectoryCapability(chips: ChipMode::Field, chipNote: 'The card already lists the records.'));

        self::assertStringEndsWith('. The card already lists the records.', $tool->description);
        self::assertStringEndsWith('already showed.', $this->chipsDescription($tool));
    }

    public function testWithoutANoteTheDescriptionIsUnchanged(): void
    {
        self::assertSame('Show records.', $this->tool(new DirectoryCapability(chips: ChipMode::Field))->description);
    }

    private function tool(DirectoryCapability $capability): ToolSpec
    {
        foreach ((new ToolSurface(new CapabilityRegistry([$capability])))->tools() as $tool) {
            if ('present_records' === $tool->name) {
                return $tool;
            }
        }

        self::fail('present_records is not on the surface');
    }

    private function chipsDescription(ToolSpec $tool): string
    {
        $properties = Scalar::keyed($tool->inputSchema['properties'] ?? null);

        return Scalar::string(Scalar::keyed($properties['suggestions'] ?? null)['description'] ?? null);
    }
}
