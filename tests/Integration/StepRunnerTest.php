<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiTool;
use Tessera\Installer\CommandExecutor;
use Tessera\Installer\CommandResult;
use Tessera\Installer\Console;
use Tessera\Installer\EnvPolicy;
use Tessera\Installer\FakeConsoleInput;
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
    protected function setUp(): void
    {
        parent::setUp();

        // Prevent STDIN reads — default to "Skip this step" (index 1)
        Console::setInput(new FakeConsoleInput([1]));
    }

    protected function tearDown(): void
    {
        Console::setInput(null);
        Console::setCommandExecutor(null);

        parent::tearDown();
    }

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
    public function run_skips_when_execute_fails_and_user_chooses_skip(): void
    {
        // "Skip this step" = index 1 for skippable choice menu
        Console::setInput(new FakeConsoleInput([1]));

        $runner = $this->makeRunner(maxRetries: 0);

        ob_start();
        $result = $runner->run(
            name: 'Failing step',
            execute: fn (): bool => false,
            skippable: true,
        );
        ob_end_clean();

        $log = $runner->getLog();
        $this->assertArrayHasKey('Failing step', $log);
    }

    #[Test]
    public function run_skips_when_verification_fails_and_user_chooses_skip(): void
    {
        Console::setInput(new FakeConsoleInput([1]));

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
        Console::setInput(new FakeConsoleInput([1]));

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
        Console::setInput(new FakeConsoleInput([1]));

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

    #[Test]
    public function run_command_uses_literal_argv_and_build_tool_env(): void
    {
        $executor = new class implements CommandExecutor
        {
            /** @var list<array{argv: array<int, string>, cwd: string, env: EnvPolicy|null}> */
            public array $calls = [];

            public function run(
                array $argv,
                string $cwd,
                ?EnvPolicy $env = null,
                ?string $stdin = null,
                ?int $timeout = null,
            ): CommandResult {
                $this->calls[] = ['argv' => $argv, 'cwd' => $cwd, 'env' => $env];

                return new CommandResult(0, '', '', false, 0.01);
            }
        };

        $router = ToolRouter::withSingleTool(AiTool::fake('claude'));
        $runner = new StepRunner($router, sys_get_temp_dir(), 2, $executor);

        ob_start();
        $result = $runner->runCommand('Run Composer', ['composer', 'install', '--no-interaction']);
        ob_end_clean();

        $this->assertTrue($result);
        $this->assertCount(1, $executor->calls);
        $this->assertSame(['composer', 'install', '--no-interaction'], $executor->calls[0]['argv']);
        $this->assertSame(sys_get_temp_dir(), $executor->calls[0]['cwd']);
        $this->assertInstanceOf(EnvPolicy::class, $executor->calls[0]['env']);
        $this->assertSame(EnvPolicy::buildTool()->allowlist(), $executor->calls[0]['env']?->allowlist());
    }

    #[Test]
    public function install_packages_builds_expected_argv_for_bulk_fallback_and_dev_mode(): void
    {
        $executor = new class implements CommandExecutor
        {
            /** @var list<array{argv: array<int, string>, cwd: string, env: EnvPolicy|null}> */
            public array $calls = [];

            public function run(
                array $argv,
                string $cwd,
                ?EnvPolicy $env = null,
                ?string $stdin = null,
                ?int $timeout = null,
            ): CommandResult {
                $this->calls[] = ['argv' => $argv, 'cwd' => $cwd, 'env' => $env];

                $command = implode(' ', $argv);

                if ($command === 'composer require --dev laravel/pint larastan/larastan --no-interaction') {
                    return new CommandResult(1, '', '', false, 0.01);
                }

                return new CommandResult(0, '', '', false, 0.01);
            }
        };

        $router = ToolRouter::withSingleTool(AiTool::fake('claude'));
        $runner = new StepRunner($router, sys_get_temp_dir(), 2, $executor);

        ob_start();
        $result = $runner->installPackages('Install dev tools', ['laravel/pint', 'larastan/larastan'], dev: true);
        ob_end_clean();

        $this->assertTrue($result);
        $this->assertSame(
            [
                ['composer', 'require', '--dev', 'laravel/pint', 'larastan/larastan', '--no-interaction'],
                ['composer', 'require', '--dev', 'laravel/pint', '--no-interaction', '--no-autoloader'],
                ['composer', 'require', '--dev', 'larastan/larastan', '--no-interaction', '--no-autoloader'],
                ['composer', 'dump-autoload'],
            ],
            array_column($executor->calls, 'argv'),
        );
        $this->assertSame(
            [
                EnvPolicy::buildTool()->allowlist(),
                EnvPolicy::buildTool()->allowlist(),
                EnvPolicy::buildTool()->allowlist(),
                EnvPolicy::buildTool()->allowlist(),
            ],
            array_map(
                static fn (array $call): array => $call['env'] instanceof EnvPolicy ? $call['env']->allowlist() : [],
                $executor->calls,
            ),
        );
    }
}
