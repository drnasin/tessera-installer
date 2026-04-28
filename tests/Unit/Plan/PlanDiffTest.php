<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Complexity;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanDiff;
use Tessera\Installer\Plan\PlanStep;

final class PlanDiffTest extends TestCase
{
    #[Test]
    public function identical_plans_produce_empty_diff(): void
    {
        $a = $this->compile([
            PlanStep::build('one', 'One', Complexity::SIMPLE, 'A'),
            PlanStep::build('two', 'Two', Complexity::MEDIUM, 'B', dependencies: ['one']),
        ]);
        $b = $this->compile([
            PlanStep::build('one', 'One', Complexity::SIMPLE, 'A'),
            PlanStep::build('two', 'Two', Complexity::MEDIUM, 'B', dependencies: ['one']),
        ]);

        $diff = PlanDiff::between($a, $b);

        $this->assertTrue($diff->isEmpty());
        $this->assertTrue($diff->hashesMatch());
    }

    #[Test]
    public function detects_added_step(): void
    {
        $before = $this->compile([
            PlanStep::build('one', 'One', Complexity::SIMPLE, 'A'),
        ]);
        $after = $this->compile([
            PlanStep::build('one', 'One', Complexity::SIMPLE, 'A'),
            PlanStep::build('two', 'Two', Complexity::SIMPLE, 'B'),
        ]);

        $diff = PlanDiff::between($before, $after);

        $this->assertSame(['two'], $diff->addedSteps);
        $this->assertSame([], $diff->removedSteps);
    }

    #[Test]
    public function detects_removed_step(): void
    {
        $before = $this->compile([
            PlanStep::build('one', 'One', Complexity::SIMPLE, 'A'),
            PlanStep::build('two', 'Two', Complexity::SIMPLE, 'B'),
        ]);
        $after = $this->compile([
            PlanStep::build('one', 'One', Complexity::SIMPLE, 'A'),
        ]);

        $diff = PlanDiff::between($before, $after);

        $this->assertSame(['two'], $diff->removedSteps);
    }

    #[Test]
    public function detects_prompt_change(): void
    {
        $before = $this->compile([PlanStep::build('a', 'A', Complexity::SIMPLE, 'before body')]);
        $after = $this->compile([PlanStep::build('a', 'A', Complexity::SIMPLE, 'after body')]);

        $diff = PlanDiff::between($before, $after);

        $this->assertSame(['a'], $diff->promptChanged);
    }

    #[Test]
    public function detects_dependency_change(): void
    {
        $before = $this->compile([
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'A'),
            PlanStep::build('b', 'B', Complexity::SIMPLE, 'B'),
        ]);
        $after = $this->compile([
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'A'),
            PlanStep::build('b', 'B', Complexity::SIMPLE, 'B', dependencies: ['a']),
        ]);

        $diff = PlanDiff::between($before, $after);

        $this->assertSame(['b'], $diff->dependenciesChanged);
    }

    #[Test]
    public function detects_adapter_or_model_hint_change(): void
    {
        $before = $this->compile([
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'body', adapterHint: 'claude', modelHint: 'claude-haiku-4-5-20251001'),
        ]);
        $after = $this->compile([
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'body', adapterHint: 'gemini', modelHint: 'gemini-2.0-flash'),
        ]);

        $diff = PlanDiff::between($before, $after);

        $this->assertSame(['a'], $diff->hintsChanged);
    }

    #[Test]
    public function detects_complexity_change(): void
    {
        $before = $this->compile([PlanStep::build('a', 'A', Complexity::SIMPLE, 'body')]);
        $after = $this->compile([PlanStep::build('a', 'A', Complexity::COMPLEX, 'body')]);

        $diff = PlanDiff::between($before, $after);

        $this->assertSame(['a'], $diff->complexityChanged);
    }

    #[Test]
    public function detects_stack_change(): void
    {
        $before = (new PlanCompiler)->compile('laravel', [PlanStep::build('a', 'A', Complexity::SIMPLE, 'body')]);
        $after = (new PlanCompiler)->compile('node', [PlanStep::build('a', 'A', Complexity::SIMPLE, 'body')]);

        $diff = PlanDiff::between($before, $after);

        $this->assertTrue($diff->stackChanged);
        $this->assertFalse($diff->isEmpty());
    }

    #[Test]
    public function to_array_serializes_all_categories(): void
    {
        $before = $this->compile([PlanStep::build('a', 'A', Complexity::SIMPLE, 'one')]);
        $after = $this->compile([PlanStep::build('a', 'A', Complexity::SIMPLE, 'two')]);

        $arr = PlanDiff::between($before, $after)->toArray();

        $this->assertArrayHasKey('prompt_changed', $arr);
        $this->assertArrayHasKey('added_steps', $arr);
        $this->assertArrayHasKey('removed_steps', $arr);
        $this->assertArrayHasKey('hints_changed', $arr);
    }

    private function compile(array $steps): \Tessera\Installer\Plan\CompiledPlan
    {
        return (new PlanCompiler)->compile('test', $steps);
    }
}
