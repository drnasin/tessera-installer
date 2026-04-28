<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tessera\Installer\Complexity;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanStep;

final class PlanCompilerTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera-plan-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
    }

    #[Test]
    public function compile_produces_plan_with_valid_hash(): void
    {
        $compiler = new PlanCompiler;

        $plan = $compiler->compile('laravel', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'do A'),
            PlanStep::build('b', 'B', Complexity::MEDIUM, 'do B', dependencies: ['a']),
        ]);

        $this->assertTrue($plan->isHashValid());
        $this->assertSame('laravel', $plan->stack);
        $this->assertCount(2, $plan->steps);
    }

    #[Test]
    public function compile_rejects_duplicate_step_ids(): void
    {
        $compiler = new PlanCompiler;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate step id");

        $compiler->compile('laravel', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'first'),
            PlanStep::build('a', 'A again', Complexity::SIMPLE, 'second'),
        ]);
    }

    #[Test]
    public function compile_rejects_unresolved_dependency(): void
    {
        $compiler = new PlanCompiler;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("unknown step 'ghost'");

        $compiler->compile('laravel', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'do', dependencies: ['ghost']),
        ]);
    }

    #[Test]
    public function compile_rejects_dependency_cycle(): void
    {
        $compiler = new PlanCompiler;

        $this->expectException(RuntimeException::class);

        $compiler->compile('laravel', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'A', dependencies: ['b']),
            PlanStep::build('b', 'B', Complexity::SIMPLE, 'B', dependencies: ['a']),
        ]);
    }

    #[Test]
    public function write_then_read_round_trips_hash_intact(): void
    {
        $compiler = new PlanCompiler;
        $original = $compiler->compile('laravel', [
            PlanStep::build('a', 'A', Complexity::COMPLEX, 'big prompt body'),
            PlanStep::build('b', 'B', Complexity::MEDIUM, 'medium prompt', dependencies: ['a']),
        ]);

        $compiler->write($original, $this->tmpFile);
        $restored = $compiler->read($this->tmpFile);

        $this->assertSame($original->planHash, $restored->planHash);
        $this->assertCount(2, $restored->steps);
        $this->assertTrue($restored->isHashValid());
    }

    #[Test]
    public function read_refuses_plan_with_tampered_hash(): void
    {
        $compiler = new PlanCompiler;
        $plan = $compiler->compile('laravel', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'body'),
        ]);

        $compiler->write($plan, $this->tmpFile);

        // Corrupt the hash field
        $raw = file_get_contents($this->tmpFile);
        $tampered = preg_replace('/"plan_hash":\s*"[a-f0-9]+"/', '"plan_hash": "deadbeef"', $raw, 1);
        file_put_contents($this->tmpFile, $tampered);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hash mismatch');

        $compiler->read($this->tmpFile);
    }

    #[Test]
    public function read_refuses_missing_file(): void
    {
        $compiler = new PlanCompiler;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $compiler->read('/tmp/does-not-exist-'.bin2hex(random_bytes(4)).'.json');
    }

    #[Test]
    public function read_refuses_invalid_schema(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'schema' => 'tessera.state/v1',
            'stack' => 'laravel',
            'steps' => [],
            'plan_hash' => 'x',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid plan');

        (new PlanCompiler)->read($this->tmpFile);
    }
}
