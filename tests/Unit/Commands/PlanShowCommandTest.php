<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Commands;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiTool;
use Tessera\Installer\Commands\PlanShowCommand;
use Tessera\Installer\Complexity;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanStep;
use Tessera\Installer\ToolRouter;

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

    #[Test]
    public function not_found_error_includes_hint(): void
    {
        ob_start();
        (new PlanShowCommand)->run(['/tmp/no-plan-'.bin2hex(random_bytes(4)).'.json']);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('tessera plan compile', $output);
    }

    #[Test]
    public function help_flag_returns_zero_and_prints_usage(): void
    {
        foreach (['--help', '-h'] as $flag) {
            ob_start();
            $code = (new PlanShowCommand)->run([$flag]);
            $output = (string) ob_get_clean();

            $this->assertSame(0, $code, "Flag {$flag} should exit 0");
            $this->assertStringContainsString('tessera plan show', $output);
        }
    }

    #[Test]
    public function resolves_concrete_adapter_and_model_when_tools_are_detectable(): void
    {
        // Inject an explicit router with a faked Claude so resolution is
        // deterministic regardless of what is installed on the runner. The
        // default preference routes SIMPLE → claude haiku, COMPLEX → claude opus.
        $router = new ToolRouter(['claude' => AiTool::fake('claude')]);

        ob_start();
        $code = (new PlanShowCommand($router))->run([$this->planPath]);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('adapter:    claude', $output);
        $this->assertStringContainsString('claude-haiku-4-5', $output);
        $this->assertStringContainsString('claude-opus-4-8', $output);
        $this->assertStringContainsString('resolved now; may differ at run time', $output);
        // The unresolved placeholders must NOT appear when resolution succeeds.
        $this->assertStringNotContainsString('(router)', $output);
        $this->assertStringNotContainsString('(default)', $output);
    }

    #[Test]
    public function keeps_placeholders_when_router_resolves_nothing(): void
    {
        // A router with no tools resolves nothing for any complexity, so the
        // output must keep the (router)/(default) placeholders — deterministic
        // even on a machine where claude IS installed (we never auto-detect here).
        $emptyRouter = new ToolRouter([]);

        ob_start();
        $code = (new PlanShowCommand($emptyRouter))->run([$this->planPath]);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('adapter:    (router)    model: (default)', $output);
        $this->assertStringNotContainsString('resolved now', $output);
    }
}
