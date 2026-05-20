<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NewCommandDangerousDirectoryTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function dangerousInvocations(): array
    {
        return [
            'cwd dot' => [['.', '--force'], 'Invalid directory name'],
            'parent dotdot' => [['..', '--force'], 'Invalid directory name'],
            'leading dash' => [['-foo', '--force'], 'Invalid directory name'],
        ];
    }

    #[Test]
    #[DataProvider('dangerousInvocations')]
    public function dangerous_new_targets_fail_before_side_effects(array $args, string $expectedOutput): void
    {
        $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_cli_guard_'.bin2hex(random_bytes(4));
        mkdir($sandbox, 0755, true);
        file_put_contents($sandbox.DIRECTORY_SEPARATOR.'canary.txt', 'do not delete');

        try {
            $result = $this->runTessera(array_merge(['new'], $args), $sandbox);

            $this->assertSame(1, $result['exit']);
            $this->assertStringContainsString($expectedOutput, $this->stripAnsi($result['combined']));
            $this->assertFileExists($sandbox.DIRECTORY_SEPARATOR.'canary.txt');
        } finally {
            $this->bestEffortDelete($sandbox);
        }
    }

    /**
     * @param  list<string>  $args
     * @return array{exit: int, combined: string}
     */
    private function runTessera(array $args, string $cwd): array
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
            $cwd,
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
