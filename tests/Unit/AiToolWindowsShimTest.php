<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tessera\Installer\AiResponse;
use Tessera\Installer\AiTool;

/**
 * Regression coverage for issue #48 / #49.
 *
 * `AiTool::execute()` spawns the AI CLI with array argv. On Windows,
 * proc_open() with an array uses CreateProcess, which appends only `.exe`
 * and ignores PATHEXT — so a bare `claude`/`gemini`/`codex` that exists
 * only as a `.cmd` npm shim never starts and `tessera new` dies on its
 * first AI call.
 *
 * These tests drive the REAL execute() array path (not a faked executor)
 * against a fake CLI shim placed on PATH:
 *   - Windows: `shim.cmd`
 *   - POSIX:   executable `shim` shell script (chmod 0755)
 *
 * On the unfixed code the Windows case fails ("Failed to start AI process");
 * once execute() routes argv through WindowsCommandResolver the shim resolves
 * and its stdout is captured. CI runs this on Ubuntu/Windows/macOS with zero
 * AI tokens.
 *
 * The AiTool is constructed via reflection (mirroring AiToolProgressTickTest)
 * so its locked claude/gemini/codex argv can be pointed at the shim binary
 * while still exercising the genuine proc_open spawn.
 */
final class AiToolWindowsShimTest extends TestCase
{
    private ?string $shimDir = null;

    private ?string $originalPath = null;

    protected function tearDown(): void
    {
        if ($this->originalPath !== null) {
            putenv('PATH='.$this->originalPath);
            $this->originalPath = null;
        }

        if ($this->shimDir !== null && is_dir($this->shimDir)) {
            foreach (glob($this->shimDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->shimDir);
            $this->shimDir = null;
        }
    }

    #[Test]
    public function execute_launches_a_bare_shim_resolved_from_path(): void
    {
        $marker = 'hello-from-shim-'.bin2hex(random_bytes(3));
        $shimName = 'tessera-shim-'.bin2hex(random_bytes(3));
        $this->installShimOnPath($shimName, $marker);

        // Bare binary name (no extension, no path) — this is exactly what
        // tools() produces for claude/gemini/codex. On Windows the bare name
        // must be resolved to the `.cmd` shim, which is the bug under test.
        $tool = $this->shimTool($shimName);

        $response = $tool->execute('ignored-prompt', (string) getcwd(), 30);

        $this->assertInstanceOf(AiResponse::class, $response);
        $this->assertTrue(
            $response->success,
            'execute() must launch the .cmd/.sh shim and exit 0. error='.$response->error,
        );
        $this->assertSame(0, $response->exitCode);
        $this->assertStringContainsString($marker, $response->output);
    }

    /**
     * Build an AiTool whose execute argv is a single bare shim name.
     * The private constructor is reached via reflection for this test only.
     */
    private function shimTool(string $shimName): AiTool
    {
        $reflection = new ReflectionClass(AiTool::class);
        /** @var AiTool $tool */
        $tool = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('name')->setValue($tool, 'claude');
        $reflection->getProperty('config')->setValue($tool, [
            'binary' => $shimName,
            'detect' => $shimName.' --version',
            'execute' => [$shimName],
            // stdin=false → the prompt is appended as the final argv element
            // and ignored by the shim; the shim just echoes its marker.
            'stdin' => false,
        ]);
        $reflection->getProperty('version')->setValue($tool, 'test');

        return $tool;
    }

    /**
     * Create a fake CLI shim that prints $marker and exits 0, then prepend
     * its directory to PATH. Windows → .cmd; POSIX → executable shell script.
     */
    private function installShimOnPath(string $shimName, string $marker): void
    {
        $this->shimDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_aishim_'.bin2hex(random_bytes(4));
        mkdir($this->shimDir, 0755, true);
        $this->originalPath = getenv('PATH') ?: '';

        if (PHP_OS_FAMILY === 'Windows') {
            $scriptPath = $this->shimDir.DIRECTORY_SEPARATOR.$shimName.'.cmd';
            file_put_contents($scriptPath, "@echo off\r\necho {$marker}\r\nexit /b 0\r\n");
        } else {
            $scriptPath = $this->shimDir.DIRECTORY_SEPARATOR.$shimName;
            file_put_contents($scriptPath, "#!/bin/sh\necho {$marker}\n");
            chmod($scriptPath, 0755);
        }

        putenv('PATH='.$this->shimDir.PATH_SEPARATOR.$this->originalPath);
    }
}
