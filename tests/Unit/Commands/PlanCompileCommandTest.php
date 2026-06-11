<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Commands;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Commands\PlanCompileCommand;
use Tessera\Installer\Plan\PlanCompiler;

final class PlanCompileCommandTest extends TestCase
{
    private string $tmpDir;

    private string $manifestPath;

    private string $outputPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera-cli-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        $this->manifestPath = $this->tmpDir.'/stack.yaml';
        $this->outputPath = $this->tmpDir.'/plan.json';

        file_put_contents($this->manifestPath, <<<YAML
            name: tiny
            label: "Tiny"
            description: "x"
            steps:
              - id: hello
                complexity: simple
                prompt: "say hi"
            YAML);
    }

    protected function tearDown(): void
    {
        foreach ([$this->manifestPath, $this->outputPath] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function description_is_one_line(): void
    {
        $this->assertNotSame('', (new PlanCompileCommand)->description());
    }

    #[Test]
    public function missing_manifest_argument_returns_one(): void
    {
        ob_start();
        $code = (new PlanCompileCommand)->run([]);
        ob_end_clean();

        $this->assertSame(1, $code);
    }

    #[Test]
    public function help_flag_returns_zero_and_prints_usage(): void
    {
        foreach (['--help', '-h'] as $flag) {
            ob_start();
            $code = (new PlanCompileCommand)->run([$flag]);
            $output = (string) ob_get_clean();

            $this->assertSame(0, $code, "Flag {$flag} should exit 0");
            $this->assertStringContainsString('tessera plan compile', $output);
            $this->assertStringContainsString('<manifest.yaml>', $output);
        }
    }

    #[Test]
    public function compiles_and_writes_plan_to_explicit_output(): void
    {
        ob_start();
        $code = (new PlanCompileCommand)->run([$this->manifestPath, '-o', $this->outputPath]);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertFileExists($this->outputPath);

        $plan = (new PlanCompiler)->read($this->outputPath);
        $this->assertSame('tiny', $plan->stack);
        $this->assertCount(1, $plan->steps);
    }

    #[Test]
    public function long_form_output_flag_is_respected(): void
    {
        ob_start();
        $code = (new PlanCompileCommand)->run([$this->manifestPath, '--output='.$this->outputPath]);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertFileExists($this->outputPath);
    }
}
