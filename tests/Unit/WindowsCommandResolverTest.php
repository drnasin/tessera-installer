<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\WindowsCommandResolver;

/**
 * Unit coverage for the shared Windows binary resolver (issue #50).
 *
 * The resolver centralizes the logic previously duplicated in CommandRunner
 * and AbstractAdapter:
 *   - POSIX: argv is returned unchanged.
 *   - Windows: a bare binary is resolved against PATH (honouring PATHEXT);
 *     `.cmd`/`.bat` wrappers are prefixed with `cmd.exe /D /S /C`;
 *     `.exe` (and absolute paths) pass through without the cmd wrapper.
 */
final class WindowsCommandResolverTest extends TestCase
{
    private ?string $tmpDir = null;

    private ?string $originalPath = null;

    protected function tearDown(): void
    {
        if ($this->originalPath !== null) {
            putenv('PATH='.$this->originalPath);
            $this->originalPath = null;
        }

        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
            $this->tmpDir = null;
        }
    }

    #[Test]
    public function empty_argv_is_returned_unchanged(): void
    {
        $this->assertSame([], WindowsCommandResolver::prepare([], null));
    }

    #[Test]
    public function posix_returns_argv_unchanged(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX-only passthrough test.');
        }

        $argv = ['claude', '-p', '--output-format', 'text'];

        $this->assertSame($argv, WindowsCommandResolver::prepare($argv, getcwd()));
    }

    #[Test]
    public function windows_wraps_resolved_cmd_shim_with_command_processor(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only .cmd wrapping test.');
        }

        $name = $this->installShim('cmd', 'tessera-res-'.bin2hex(random_bytes(3)));

        $prepared = WindowsCommandResolver::prepare([$name, '-p'], (string) getcwd());

        // Expect: [cmd.exe, /D, /S, /C, <abs path to shim.cmd>, -p]
        $this->assertGreaterThanOrEqual(6, count($prepared));
        $this->assertSame('/D', $prepared[1]);
        $this->assertSame('/S', $prepared[2]);
        $this->assertSame('/C', $prepared[3]);
        $this->assertStringEndsWith('.cmd', strtolower($prepared[4]));
        $this->assertTrue(is_file($prepared[4]), 'resolved shim path must exist');
        $this->assertSame('-p', $prepared[5]);

        $processor = strtolower(basename($prepared[0]));
        $this->assertStringContainsString('cmd', $processor);
    }

    #[Test]
    public function windows_passes_through_exe_without_cmd_wrapper(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only .exe passthrough test.');
        }

        $name = $this->installShim('exe', 'tessera-res-'.bin2hex(random_bytes(3)));

        $prepared = WindowsCommandResolver::prepare([$name, '--flag'], (string) getcwd());

        // Resolved to the .exe on PATH, with no cmd.exe wrapper prepended.
        $this->assertCount(2, $prepared);
        $this->assertStringEndsWith('.exe', strtolower($prepared[0]));
        $this->assertTrue(is_file($prepared[0]));
        $this->assertSame('--flag', $prepared[1]);
    }

    #[Test]
    public function windows_unresolvable_bare_binary_is_left_untouched(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only unresolved-binary test.');
        }

        $argv = ['tessera-nonexistent-'.bin2hex(random_bytes(4)), '--x'];

        // No file on PATH → no resolution, no cmd wrapper, argv unchanged.
        $this->assertSame($argv, WindowsCommandResolver::prepare($argv, (string) getcwd()));
    }

    /**
     * Create a fake shim of the given extension on a fresh temp dir and
     * prepend that dir to PATH. Returns the bare (extensionless) command name.
     */
    private function installShim(string $extension, string $name): string
    {
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_res_'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        $this->originalPath = getenv('PATH') ?: '';

        $scriptPath = $this->tmpDir.DIRECTORY_SEPARATOR.$name.'.'.$extension;
        file_put_contents($scriptPath, "@echo off\r\necho ok\r\nexit /b 0\r\n");

        putenv('PATH='.$this->tmpDir.PATH_SEPARATOR.$this->originalPath);

        return $name;
    }
}
