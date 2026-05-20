<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\CommandExecutor;
use Tessera\Installer\CommandResult;
use Tessera\Installer\Console;
use Tessera\Installer\EnvPolicy;

final class ConsoleCommandExecutionTest extends TestCase
{
    protected function tearDown(): void
    {
        Console::setCommandExecutor(null);

        parent::tearDown();
    }

    #[Test]
    public function exec_silent_argv_combines_stdout_and_stderr_and_defaults_to_build_tool_env(): void
    {
        $executor = new class implements CommandExecutor
        {
            /** @var array<int, string>|null */
            public ?array $argv = null;

            public ?string $cwd = null;

            public ?EnvPolicy $env = null;

            public function run(
                array $argv,
                string $cwd,
                ?EnvPolicy $env = null,
                ?string $stdin = null,
                ?int $timeout = null,
            ): CommandResult {
                $this->argv = $argv;
                $this->cwd = $cwd;
                $this->env = $env;

                return new CommandResult(
                    exitCode: 7,
                    stdout: "stdout-line\n",
                    stderr: "stderr-line\n",
                    timedOut: false,
                    durationSeconds: 0.01,
                );
            }
        };

        Console::setCommandExecutor($executor);

        $result = Console::execSilentArgv(['composer', '--version']);

        $this->assertSame(['composer', '--version'], $executor->argv);
        $this->assertSame(getcwd(), $executor->cwd);
        $this->assertInstanceOf(EnvPolicy::class, $executor->env);
        $this->assertSame(EnvPolicy::buildTool()->allowlist(), $executor->env->allowlist());
        $this->assertSame(7, $result['exit']);
        $this->assertSame("stdout-line\nstderr-line", $result['output']);
    }

    #[Test]
    public function exec_argv_uses_explicit_env_and_prints_stdout(): void
    {
        $executor = new class implements CommandExecutor
        {
            public ?EnvPolicy $env = null;

            public function run(
                array $argv,
                string $cwd,
                ?EnvPolicy $env = null,
                ?string $stdin = null,
                ?int $timeout = null,
            ): CommandResult {
                $this->env = $env;

                return new CommandResult(
                    exitCode: 0,
                    stdout: 'ok',
                    stderr: '',
                    timedOut: false,
                    durationSeconds: 0.01,
                );
            }
        };

        Console::setCommandExecutor($executor);

        ob_start();
        $exit = Console::execArgv(['php', '--version'], env: EnvPolicy::minimal());
        $output = ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $output);
        $this->assertInstanceOf(EnvPolicy::class, $executor->env);
        $this->assertSame(EnvPolicy::minimal()->allowlist(), $executor->env->allowlist());
    }
}
