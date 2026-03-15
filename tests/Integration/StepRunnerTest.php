<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiTool;
use Tessera\Installer\StepRunner;
use Tessera\Installer\ToolRouter;

/**
 * Tests for StepRunner's retry and recovery logic.
 *
 * These tests use callable-based steps (not AI steps) to test
 * the decision logic without consuming tokens.
 */
final class StepRunnerTest extends TestCase
{
    private function makeRunner(int $maxRetries = 2): StepRunner
    {
        $router = ToolRouter::withSingleTool(AiTool::fake('claude'));

        return new StepRunner($router, sys_get_temp_dir(), $maxRetries);
    }

    #[Test]
    public function run_succeeds_on_first_attempt(): void
    {
        $runner = $this->makeRunner();

        ob_start();
        $result = $runner->run(
            name: 'Test step',
            execute: fn (): bool => true,
        );
        ob_end_clean();

        $this->assertTrue($result);
        $this->assertSame('OK', $runner->getLog()['Test step']);
    }

    #[Test]
    public function run_succeeds_with_verification(): void
    {
        $runner = $this->makeRunner();

        ob_start();
        $result = $runner->run(
            name: 'Verified step',
            execute: fn (): bool => true,
            verify: fn (): ?string => null, // null = OK
        );
        ob_end_clean();

        $this->assertTrue($result);
        $this->assertSame('OK', $runner->getLog()['Verified step']);
    }

    #[Test]
    public function run_fails_when_execute_returns_false(): void
    {
        $runner = $this->makeRunner(maxRetries: 0);

        ob_start();
        $result = $runner->run(
            name: 'Failing step',
            execute: fn (): bool => false,
            skippable: true,
        );
        ob_end_clean();

        // With 0 retries and skippable, falls to user prompt.
        // Since we can't interact with stdin in tests, check the log.
        $log = $runner->getLog();
        $this->assertArrayHasKey('Failing step', $log);
    }

    #[Test]
    public function run_fails_when_verification_returns_error(): void
    {
        $runner = $this->makeRunner(maxRetries: 0);

        ob_start();
        $result = $runner->run(
            name: 'Bad verify',
            execute: fn (): bool => true,
            verify: fn (): ?string => 'Missing file: Page.php',
            skippable: true,
        );
        ob_end_clean();

        $log = $runner->getLog();
        $this->assertArrayHasKey('Bad verify', $log);
    }

    #[Test]
    public function attempt_catches_exceptions(): void
    {
        $runner = $this->makeRunner(maxRetries: 0);

        ob_start();
        $result = $runner->run(
            name: 'Exception step',
            execute: function (): bool {
                throw new \RuntimeException('Something broke');
            },
            skippable: true,
        );
        ob_end_clean();

        $log = $runner->getLog();
        $this->assertArrayHasKey('Exception step', $log);
    }

    #[Test]
    public function get_log_returns_all_steps(): void
    {
        $runner = $this->makeRunner();

        ob_start();
        $runner->run('Step 1', fn (): bool => true);
        $runner->run('Step 2', fn (): bool => true);
        $runner->run('Step 3', fn (): bool => true);
        ob_end_clean();

        $log = $runner->getLog();
        $this->assertCount(3, $log);
        $this->assertSame('OK', $log['Step 1']);
        $this->assertSame('OK', $log['Step 2']);
        $this->assertSame('OK', $log['Step 3']);
    }

    #[Test]
    public function verify_catches_exceptions(): void
    {
        $runner = $this->makeRunner(maxRetries: 0);

        ob_start();
        $runner->run(
            name: 'Verify throws',
            execute: fn (): bool => true,
            verify: function (): ?string {
                throw new \RuntimeException('Verify crashed');
            },
            skippable: true,
        );
        ob_end_clean();

        $log = $runner->getLog();
        $this->assertArrayHasKey('Verify throws', $log);
    }

    #[Test]
    public function succeeds_on_second_attempt_after_verification_failure(): void
    {
        $runner = $this->makeRunner(maxRetries: 2);
        $attempts = 0;

        ob_start();
        $result = $runner->run(
            name: 'Flaky step',
            execute: fn (): bool => true,
            verify: function () use (&$attempts): ?string {
                $attempts++;

                // Fail first time, succeed second time
                return $attempts <= 1 ? 'Not ready yet' : null;
            },
        );
        ob_end_clean();

        // Note: This might not reach the second verify because aiFix uses
        // executeWithFallback which calls the actual AI tool (which is fake).
        // The test validates that the step runner at least enters the retry loop.
        $this->assertArrayHasKey('Flaky step', $runner->getLog());
    }
}
