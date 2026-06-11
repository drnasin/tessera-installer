<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end guard for subcommand `--help`/`-h` (issue #19).
 *
 * The most common CLI reflex — `tessera new --help` — used to fall through
 * the `new` argument parser and produce the confusing "Directory name cannot
 * start with a dash" error with exit 1. Help requests for `new` and the
 * `plan` subcommands must now exit 0 and print a per-command usage block.
 *
 * Driven through the real binary via proc_open so the bin/tessera dispatch
 * and each command's run() are exercised together, the same way a user runs
 * them.
 */
final class SubcommandHelpTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    /**
     * @return array<string, array{0: list<string>, 1: list<string>}>
     */
    public static function helpInvocations(): array
    {
        return [
            'new --help' => [['new', '--help'], ['tessera new', '--force', '--stack=', '--requirements-fixture=', 'laravel']],
            'new -h' => [['new', '-h'], ['tessera new', '--force']],
            'plan compile --help' => [['plan', 'compile', '--help'], ['tessera plan compile', '<manifest.yaml>']],
            'plan compile -h' => [['plan', 'compile', '-h'], ['tessera plan compile']],
            'plan show --help' => [['plan', 'show', '--help'], ['tessera plan show']],
            'plan show -h' => [['plan', 'show', '-h'], ['tessera plan show']],
            'plan diff --help' => [['plan', 'diff', '--help'], ['tessera plan diff']],
            'plan diff -h' => [['plan', 'diff', '-h'], ['tessera plan diff']],
        ];
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $expectedSubstrings
     */
    #[Test]
    #[DataProvider('helpInvocations')]
    public function help_requests_exit_zero_and_print_usage(array $args, array $expectedSubstrings): void
    {
        $result = $this->runTessera($args);

        $this->assertSame(0, $result['exit'], "Expected exit 0 for: tessera ".implode(' ', $args)."\nOutput:\n".$result['combined']);

        $output = $this->stripAnsi($result['combined']);
        foreach ($expectedSubstrings as $needle) {
            $this->assertStringContainsString($needle, $output);
        }

        // The pre-fix bug: `new --help` produced this validation error. Guard
        // against regression for every help path.
        $this->assertStringNotContainsString('Invalid directory name', $output);
        $this->assertStringNotContainsString('Missing manifest path', $output);
        $this->assertStringNotContainsString('Need exactly two plan paths', $output);
    }

    /**
     * @param  list<string>  $args
     * @return array{exit: int, combined: string}
     */
    private function runTessera(array $args): array
    {
        $command = array_merge([PHP_BINARY, $this->repoRoot.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'tessera'], $args);
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

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }
}
