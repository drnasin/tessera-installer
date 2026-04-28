<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Adapters;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tessera\Installer\Adapters\AbstractAdapter;
use Tessera\Installer\Adapters\AdapterContext;

/**
 * Regression tests for the timeout + tree-kill behaviour added in S1
 * after the smoke test revealed that a 300-second timeout took 633
 * seconds to release control because Claude CLI grandchildren held
 * pipes open across proc_terminate.
 *
 * The fix kills the entire process tree (Windows: `taskkill /F /T`,
 * Unix: recursive pgrep + posix_kill) before proc_close, which lets
 * the cleanup return immediately.
 *
 * These tests start a long-running command and assert that runProcess
 * returns within roughly `timeout + 2s`. Without the fix, the tests
 * would hang for the full 30s sleep duration.
 */
final class AbstractAdapterTimeoutTest extends TestCase
{
    #[Test]
    public function timeout_returns_within_threshold(): void
    {
        $adapter = $this->makeProbeAdapter();
        $longRunning = $this->longRunningCommand(30);

        $started = microtime(true);
        $response = $this->invokeRunProcess($adapter, $longRunning, null, null, 2);
        $elapsed = microtime(true) - $started;

        // Without the tree-kill fix this hangs for ~30 seconds (the full
        // sleep duration) on Windows. With the fix we should return
        // within ~3 seconds on any OS.
        $this->assertLessThan(
            6.0,
            $elapsed,
            "runProcess should return within timeout+slack, took {$elapsed}s",
        );

        $this->assertFalse($response->success);
        $this->assertSame(124, $response->exitCode);
        $this->assertStringContainsString('Timeout after 2s', $response->error);
    }

    #[Test]
    public function fast_command_returns_normally_with_zero_exit(): void
    {
        $adapter = $this->makeProbeAdapter();

        $command = PHP_OS_FAMILY === 'Windows'
            ? ['cmd', '/c', 'echo', 'hello']
            : ['sh', '-c', 'echo hello'];

        $response = $this->invokeRunProcess($adapter, $command, null, null, 5);

        $this->assertTrue($response->success);
        $this->assertSame(0, $response->exitCode);
        $this->assertStringContainsString('hello', $response->output);
    }

    #[Test]
    public function kill_process_tree_handles_invalid_pid_gracefully(): void
    {
        $method = new ReflectionMethod(AbstractAdapter::class, 'killProcessTree');

        // Should not throw on a pid that does not exist.
        $method->invoke(null, 999999999);
        $method->invoke(null, 0);
        $method->invoke(null, -1);

        $this->assertTrue(true);
    }

    private function invokeRunProcess(AbstractAdapter $adapter, array $command, ?string $stdin, ?string $cwd, int $timeout): \Tessera\Installer\AiResponse
    {
        $method = new ReflectionMethod(AbstractAdapter::class, 'runProcess');

        return $method->invoke($adapter, $command, $stdin, $cwd, $timeout);
    }

    /**
     * Use the PHP CLI itself as a cross-OS "sleep" — this is more reliable
     * than `sleep` (Unix-only) or `timeout`/`ping` (Windows quirks with
     * shell-free argv). PHP_BINARY is always available because PHPUnit
     * runs under PHP.
     *
     * @return list<string>
     */
    private function longRunningCommand(int $sleepSeconds): array
    {
        return [PHP_BINARY, '-r', 'sleep('.$sleepSeconds.');'];
    }

    private function makeProbeAdapter(): AbstractAdapter
    {
        return new class extends AbstractAdapter
        {
            public function name(): string
            {
                return 'probe';
            }

            protected function detectCommand(): array
            {
                return ['echo', 'probe'];
            }

            protected function buildExecuteCommand(string $prompt, AdapterContext $context): array
            {
                return ['echo', $prompt];
            }

            protected function usesStdin(): bool
            {
                return false;
            }
        };
    }
}
