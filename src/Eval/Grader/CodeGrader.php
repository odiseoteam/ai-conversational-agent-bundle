<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Eval\Grader;

use Odiseo\AiConversationalAgentBundle\Eval\EvalCase;
use Odiseo\AiConversationalAgentBundle\Eval\TurnRecording;
use Odiseo\AiConversationalAgentBundle\Support\Scalar;

/**
 * The core's expected keys: the calls the agent made, what it rendered and the memory it left;
 * the reply's wording is graded only for strings that must or must not appear. `rubric` goes to
 * the judge, and a vertical's state to its own grader.
 */
final class CodeGrader implements Grader
{
    public function keys(): array
    {
        return [
            'calls_tool', 'calls_one_of', 'never_calls', 'first_tool', 'first_tool_not',
            'ui_components', 'no_ui', 'skill_loaded', 'skill_not_loaded', 'no_skill_load',
            'reply_includes', 'reply_omits', 'memory_contains', 'memory_not_contains',
            'max_tool_calls', 'blocked_by',
        ];
    }

    public function grade(EvalCase $case, TurnRecording $recording): array
    {
        $failures = [];
        $expected = $case->expected;
        $tools = $recording->toolNames();

        foreach ($this->stringList($expected['calls_tool'] ?? null) as $tool) {
            if (!\in_array($tool, $tools, true)) {
                $failures[] = \sprintf('expected a call to %s; called %s', $tool, $this->render($tools));
            }
        }

        $oneOf = $this->stringList($expected['calls_one_of'] ?? null);
        if ([] !== $oneOf && [] === array_intersect($oneOf, $tools)) {
            $failures[] = \sprintf('expected one of %s; called %s', $this->render($oneOf), $this->render($tools));
        }

        foreach ($this->stringList($expected['never_calls'] ?? null) as $tool) {
            if (\in_array($tool, $tools, true)) {
                $failures[] = \sprintf('%s was called and must not be', $tool);
            }
        }

        if (isset($expected['first_tool']) && $recording->firstTool() !== $expected['first_tool']) {
            $failures[] = \sprintf('expected %s first; got %s', Scalar::string($expected['first_tool']), $recording->firstTool() ?? 'no call');
        }

        if (isset($expected['first_tool_not']) && $recording->firstTool() === $expected['first_tool_not']) {
            $failures[] = \sprintf('%s must not be the first call', Scalar::string($expected['first_tool_not']));
        }

        foreach ($this->stringList($expected['ui_components'] ?? null) as $component) {
            if (!\in_array($component, $recording->components, true)) {
                $failures[] = \sprintf('expected the %s component; rendered %s', $component, $this->render($recording->components));
            }
        }

        if (($expected['no_ui'] ?? false) && [] !== $recording->components) {
            $failures[] = \sprintf('expected no component; rendered %s', $this->render($recording->components));
        }

        if (isset($expected['skill_loaded']) && !\in_array(Scalar::string($expected['skill_loaded']), $recording->skillsLoaded, true)) {
            $failures[] = \sprintf('expected the %s skill; loaded %s', Scalar::string($expected['skill_loaded']), $this->render($recording->skillsLoaded));
        }

        if (isset($expected['skill_not_loaded']) && \in_array(Scalar::string($expected['skill_not_loaded']), $recording->skillsLoaded, true)) {
            $failures[] = \sprintf('the %s skill was loaded and must not be', Scalar::string($expected['skill_not_loaded']));
        }

        if (($expected['no_skill_load'] ?? false) && [] !== $recording->skillsLoaded) {
            $failures[] = \sprintf('expected no skill load; loaded %s', $this->render($recording->skillsLoaded));
        }

        $reply = mb_strtolower($recording->reply);
        foreach ($this->stringList($expected['reply_includes'] ?? null) as $needle) {
            if (!str_contains($reply, mb_strtolower($needle))) {
                $failures[] = \sprintf('the reply does not mention "%s"', $needle);
            }
        }

        foreach ($this->stringList($expected['reply_omits'] ?? null) as $needle) {
            if (str_contains($reply, mb_strtolower($needle))) {
                $failures[] = \sprintf('the reply mentions "%s" and must not', $needle);
            }
        }

        $stored = array_map(static fn (array $fact): string => mb_strtolower($fact['key'].' '.$fact['value']), $recording->memory);
        foreach ($this->stringList($expected['memory_contains'] ?? null) as $needle) {
            if (!$this->anyContains($stored, $needle)) {
                $failures[] = \sprintf('memory does not hold "%s"', $needle);
            }
        }

        foreach ($this->stringList($expected['memory_not_contains'] ?? null) as $needle) {
            if ($this->anyContains($stored, $needle)) {
                $failures[] = \sprintf('memory holds "%s" and must not', $needle);
            }
        }

        // The chips call that ends a turn is not part of the work the case is counting.
        if (isset($expected['max_tool_calls'])) {
            $counted = \count(array_filter($tools, static fn (string $tool): bool => 'present_suggestions' !== $tool));
            if ($counted > Scalar::int($expected['max_tool_calls'])) {
                $failures[] = \sprintf('made %d calls, more than the %d allowed', $counted, Scalar::int($expected['max_tool_calls']));
            }
        }

        foreach ($this->stringList($expected['blocked_by'] ?? null) as $gate) {
            $held = array_filter($recording->toolResults, static fn (array $result): bool => $gate === $result['reason']);
            if ([] === $held) {
                $failures[] = \sprintf('expected a call held by the %s gate', $gate);
            }
        }

        return $failures;
    }

    /** @param list<string> $haystacks */
    private function anyContains(array $haystacks, string $needle): bool
    {
        $needle = mb_strtolower($needle);
        foreach ($haystacks as $haystack) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return null === $value ? [] : [Scalar::string($value)];
        }

        return Scalar::strings($value);
    }

    /** @param list<string> $values */
    private function render(array $values): string
    {
        return [] === $values ? 'nothing' : implode(', ', $values);
    }
}
