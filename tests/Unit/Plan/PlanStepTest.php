<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Complexity;
use Tessera\Installer\Plan\PlanStep;
use Tessera\Installer\Plan\PromptFingerprint;

final class PlanStepTest extends TestCase
{
    #[Test]
    public function build_derives_fingerprint_from_prompt_body(): void
    {
        $step = PlanStep::build(
            id: 'models',
            name: 'Generate Eloquent models',
            complexity: Complexity::COMPLEX,
            prompt: 'Generate Laravel models for...',
        );

        $expected = (new PromptFingerprint('Generate Laravel models for...', '1'))->hash;

        $this->assertSame($expected, $step->promptFingerprint);
    }

    #[Test]
    public function to_array_round_trips_through_from_array(): void
    {
        $original = PlanStep::build(
            id: 'theme',
            name: 'Build the theme',
            complexity: Complexity::MEDIUM,
            prompt: 'Build a default theme',
            adapterHint: 'claude',
            modelHint: 'claude-sonnet-4-20250514',
            dependencies: ['models'],
            gates: [['type' => 'exists_all', 'paths' => ['app/Models/User.php']]],
        );

        $restored = PlanStep::fromArray($original->toArray());

        $this->assertSame($original->id, $restored->id);
        $this->assertSame($original->name, $restored->name);
        $this->assertSame($original->complexity, $restored->complexity);
        $this->assertSame($original->prompt, $restored->prompt);
        $this->assertSame($original->promptFingerprint, $restored->promptFingerprint);
        $this->assertSame($original->adapterHint, $restored->adapterHint);
        $this->assertSame($original->modelHint, $restored->modelHint);
        $this->assertSame($original->dependencies, $restored->dependencies);
        $this->assertSame($original->gates, $restored->gates);
    }

    #[Test]
    public function from_array_handles_missing_optional_fields(): void
    {
        $step = PlanStep::fromArray([
            'id' => 'minimal',
            'complexity' => 'simple',
            'prompt' => 'do thing',
            'prompt_fingerprint' => str_repeat('a', 64),
        ]);

        $this->assertSame('minimal', $step->id);
        $this->assertSame('minimal', $step->name);
        $this->assertNull($step->adapterHint);
        $this->assertNull($step->modelHint);
        $this->assertSame([], $step->dependencies);
        $this->assertSame([], $step->gates);
    }
}
