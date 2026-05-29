<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Packaging guard: the CLI must fail fast with an actionable message when
 * Composer's autoloader is unavailable, rather than starting up and crashing
 * later on a missing class.
 *
 * The binary is copied into a deeply nested sandbox so that all three
 * autoloader candidate paths resolve *inside* the (empty) sandbox tree:
 *
 *   {sandbox}/a/b/bin/tessera
 *     __DIR__/../vendor/autoload.php  -> {sandbox}/a/b/vendor/autoload.php
 *     __DIR__/../../autoload.php      -> {sandbox}/a/autoload.php
 *     __DIR__/../../../autoload.php   -> {sandbox}/autoload.php
 *
 * None of them exist, so the script must hit the missing-autoloader branch.
 */
final class MissingAutoloaderTest extends TestCase
{
    #[Test]
    public function cli_fails_fast_with_actionable_message_when_autoloader_is_missing(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_no_autoload_'.bin2hex(random_bytes(4));
        $binDir = $sandbox.DIRECTORY_SEPARATOR.'a'.DIRECTORY_SEPARATOR.'b'.DIRECTORY_SEPARATOR.'bin';
        $this->assertTrue(mkdir($binDir, 0755, true), 'Could not create sandbox bin directory.');

        $binary = $binDir.DIRECTORY_SEPARATOR.'tessera';
        $this->assertTrue(
            copy($repoRoot.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'tessera', $binary),
            'Could not copy tessera binary into sandbox.',
        );

        try {
            $result = $this->runPhpScript($binary, ['--version']);

            $this->assertSame(1, $result['exit']);
            $this->assertStringContainsStringIgnoringCase('autoloader', $result['combined']);
            // Lock the *actionable* part of the message, not just that it mentions Composer.
            $this->assertStringContainsString('composer install', $result['combined']);
        } finally {
            $this->bestEffortDelete($sandbox);
        }
    }

    /**
     * @param  list<string>  $args
     * @return array{exit: int, combined: string}
     */
    private function runPhpScript(string $script, array $args): array
    {
        $command = array_merge([PHP_BINARY, $script], $args);
        $stdoutFile = tempnam(sys_get_temp_dir(), 'tessera_stdout_');
        $stderrFile = tempnam(sys_get_temp_dir(), 'tessera_stderr_');

        if ($stdoutFile === false || $stderrFile === false) {
            throw new \RuntimeException('Could not create temp files for CLI test.');
        }

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start tessera CLI.');
        }

        fclose($pipes[0]);
        $exit = proc_close($process);

        $stdout = (string) file_get_contents($stdoutFile);
        $stderr = (string) file_get_contents($stderrFile);
        @unlink($stdoutFile);
        @unlink($stderrFile);

        return [
            'exit' => $exit,
            'combined' => $stdout."\n".$stderr,
        ];
    }

    private function bestEffortDelete(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($rii as $item) {
            if ($item->isLink()) {
                @unlink($item->getPathname());
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
