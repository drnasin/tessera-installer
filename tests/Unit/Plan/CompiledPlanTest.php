<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tessera\Installer\Complexity;
use Tessera\Installer\Plan\CompiledPlan;
use Tessera\Installer\Plan\PlanStep;
use Tessera\Installer\Schema\SchemaVersion;

final class CompiledPlanTest extends TestCase
{
    #[Test]
    public function to_array_includes_schema(): void
    {
        $plan = $this->makePlan();

        $arr = $plan->toArray();

        $this->assertSame(SchemaVersion::PLAN, $arr['schema']);
        $this->assertSame('laravel', $arr['stack']);
        $this->assertCount(2, $arr['steps']);
    }

    #[Test]
    public function from_array_restores_full_plan_identity(): void
    {
        $plan = $this->makePlan();

        $restored = CompiledPlan::fromArray($plan->toArray());

        $this->assertSame($plan->planHash, $restored->planHash);
        $this->assertSame($plan->stack, $restored->stack);
        $this->assertCount(2, $restored->steps);
        $this->assertTrue($restored->isHashValid());
    }

    #[Test]
    public function topological_order_respects_dependencies(): void
    {
        $a = PlanStep::build('a', 'A', Complexity::SIMPLE, 'do A');
        $b = PlanStep::build('b', 'B', Complexity::SIMPLE, 'do B', dependencies: ['a']);
        $c = PlanStep::build('c', 'C', Complexity::SIMPLE, 'do C', dependencies: ['b']);

        // Provide them out of order — topological sort should fix it.
        $plan = new CompiledPlan(
            stack: 'test',
            steps: [$c, $a, $b],
            requirements: [],
            planHash: CompiledPlan::computeHash([$c, $a, $b]),
            compiledAt: '2026-04-27T12:00:00Z',
            compilerVersion: '1.0',
        );

        $order = array_map(fn (PlanStep $s): string => $s->id, $plan->inTopologicalOrder());

        $this->assertSame(['a', 'b', 'c'], $order);
    }

    #[Test]
    public function topological_order_throws_on_cycle(): void
    {
        $a = PlanStep::build('a', 'A', Complexity::SIMPLE, 'A', dependencies: ['b']);
        $b = PlanStep::build('b', 'B', Complexity::SIMPLE, 'B', dependencies: ['a']);

        $plan = new CompiledPlan(
            stack: 'test',
            steps: [$a, $b],
            requirements: [],
            planHash: CompiledPlan::computeHash([$a, $b]),
            compiledAt: '2026-04-27T12:00:00Z',
            compilerVersion: '1.0',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cycle');
        $plan->inTopologicalOrder();
    }

    #[Test]
    public function plan_hash_is_invariant_under_human_field_edits(): void
    {
        $a = PlanStep::build('a', 'A', Complexity::SIMPLE, 'body');
        $aRenamed = new PlanStep(
            id: 'a',
            name: 'Different display name',
            complexity: Complexity::SIMPLE,
            prompt: 'body',
            promptFingerprint: $a->promptFingerprint,
        );

        $this->assertSame(
            CompiledPlan::computeHash([$a]),
            CompiledPlan::computeHash([$aRenamed]),
        );
    }

    #[Test]
    public function plan_hash_changes_when_prompt_fingerprint_changes(): void
    {
        $a = PlanStep::build('a', 'A', Complexity::SIMPLE, 'body v1');
        $b = PlanStep::build('a', 'A', Complexity::SIMPLE, 'body v2');

        $this->assertNotSame(
            CompiledPlan::computeHash([$a]),
            CompiledPlan::computeHash([$b]),
        );
    }

    #[Test]
    public function step_lookup_finds_by_id(): void
    {
        $plan = $this->makePlan();

        $this->assertNotNull($plan->step('models'));
        $this->assertNull($plan->step('nonexistent'));
    }

    private function makePlan(): CompiledPlan
    {
        $models = PlanStep::build('models', 'Models', Complexity::COMPLEX, 'Build models');
        $theme = PlanStep::build('theme', 'Theme', Complexity::MEDIUM, 'Build theme', dependencies: ['models']);

        return new CompiledPlan(
            stack: 'laravel',
            steps: [$models, $theme],
            requirements: ['languages' => ['hr']],
            planHash: CompiledPlan::computeHash([$models, $theme]),
            compiledAt: '2026-04-27T12:00:00Z',
            compilerVersion: '1.0',
        );
    }
}
