<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Commands;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Commands\PlanShowCommand;
use Tessera\Installer\Complexity;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanStep;

final class PlanShowCommandTest extends TestCase
{
    private string $planPath;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera-show-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $this->planPath = $dir.'/plan.json';

        $plan = (new PlanCompiler)->compile('demo', [
            PlanStep::build('a', 'Step A', Complexity::SIMPLE, 'A body'),
            PlanStep::build('b', 'Step B', Complexity::COMPLEX, 'B body', dependencies: ['a']),
        ]);
        (new PlanCompiler)->write($plan, $this->planPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->planPath)) {
            $dir = dirname($this->planPath);
            @unlink($this->planPath);
            @rmdir($dir);
        }
    }

    #[Test]
    public function prints_step_ids_and_returns_zero(): void
    {
        ob_start();
        $code = (new PlanShowCommand)->run([$this->planPath]);
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Step A', $output);
        $this->assertStringContainsString('Step B', $output);
        $this->assertStringContainsString('demo', $output);
    }

    #[Test]
    public function returns_nonzero_for_missing_plan(): void
    {
        ob_start();
        $code = (new PlanShowCommand)->run(['/tmp/no-plan-'.bin2hex(random_bytes(4)).'.json']);
        ob_end_clean();

        $this->assertSame(1, $code);
    }
}
