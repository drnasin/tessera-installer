<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Commands;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Commands\PlanDiffCommand;
use Tessera\Installer\Complexity;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanStep;

final class PlanDiffCommandTest extends TestCase
{
    private string $beforePath;

    private string $afterPath;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera-diff-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $this->beforePath = $dir.'/before.json';
        $this->afterPath = $dir.'/after.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->beforePath, $this->afterPath] as $p) {
            if (is_file($p)) {
                $dir = dirname($p);
                @unlink($p);
                if (is_dir($dir)) {
                    @rmdir($dir);
                }
            }
        }
    }

    #[Test]
    public function identical_plans_return_zero(): void
    {
        $compiler = new PlanCompiler;
        $plan = $compiler->compile('demo', [PlanStep::build('a', 'A', Complexity::SIMPLE, 'body')]);
        $compiler->write($plan, $this->beforePath);
        $compiler->write($plan, $this->afterPath);

        ob_start();
        $code = (new PlanDiffCommand)->run([$this->beforePath, $this->afterPath]);
        ob_end_clean();

        $this->assertSame(0, $code);
    }

    #[Test]
    public function different_plans_return_two(): void
    {
        $compiler = new PlanCompiler;
        $compiler->write(
            $compiler->compile('demo', [PlanStep::build('a', 'A', Complexity::SIMPLE, 'body v1')]),
            $this->beforePath,
        );
        $compiler->write(
            $compiler->compile('demo', [PlanStep::build('a', 'A', Complexity::SIMPLE, 'body v2')]),
            $this->afterPath,
        );

        ob_start();
        $code = (new PlanDiffCommand)->run([$this->beforePath, $this->afterPath]);
        $output = ob_get_clean();

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Prompt body changed', $output);
    }

    #[Test]
    public function missing_args_return_one(): void
    {
        ob_start();
        $code = (new PlanDiffCommand)->run([$this->beforePath]);
        ob_end_clean();

        $this->assertSame(1, $code);
    }

    #[Test]
    public function help_flag_returns_zero_and_prints_usage(): void
    {
        foreach (['--help', '-h'] as $flag) {
            ob_start();
            $code = (new PlanDiffCommand)->run([$flag]);
            $output = (string) ob_get_clean();

            $this->assertSame(0, $code, "Flag {$flag} should exit 0");
            $this->assertStringContainsString('tessera plan diff', $output);
        }
    }
}
