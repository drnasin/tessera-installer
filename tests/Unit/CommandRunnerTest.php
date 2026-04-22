<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\CommandRunner;
use Tessera\Installer\EnvPolicy;

/**
 * CommandRunner security & correctness tests.
 *
 * Focus areas:
 *   - array argv must not be shell-interpreted (injection is impossible)
 *   - env allowlist must actually filter (credentials don't leak)
 *   - non-blocking multiplex must handle large stderr without deadlock
 *   - timeout must terminate the child
 */
final class CommandRunnerTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
    }

    private function runner(int $timeout = 30): CommandRunner
    {
        return new CommandRunner(defaultTimeout: $timeout);
    }

    #[Test]
    public function runs_simple_command_and_captures_stdout(): void
    {
        $result = $this->runner()->run(
            argv: [PHP_BINARY, '-r', 'echo "hello";'],
            cwd: $this->cwd,
            env: EnvPolicy::minimal(),
        );

        $this->assertTrue($result->succeeded(), $result->stderr);
        $this->assertStringContainsString('hello', $result->stdout);
    }

    #[Test]
    public function empty_argv_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->runner()->run(argv: [], cwd: $this->cwd);
    }

    #[Test]
    public function argv_containing_shell_metacharacters_is_passed_literally(): void
    {
        // Classic shell injection payload. If argv went through a shell,
        // this would run `echo first; echo PWNED` and PWNED would appear
        // in stdout. With array argv, the whole string is one argument to
        // `echo` and nothing is interpreted.
        $payload = 'first; echo PWNED';

        $result = $this->runner()->run(
            argv: [PHP_BINARY, '-r', 'echo $argv[1];', '--', $payload],
            cwd: $this->cwd,
            env: EnvPolicy::minimal(),
        );

        $this->assertTrue($result->succeeded(), $result->stderr);
        $this->assertStringContainsString('first; echo PWNED', $result->stdout);
        $this->assertStringNotContainsString('PWNED'."\n", $result->stdout, 'semicolon must not spawn a second command');
    }

    #[Test]
    public function backtick_and_dollar_paren_are_not_evaluated(): void
    {
        $payload = '`whoami`$(whoami)';

        $result = $this->runner()->run(
            argv: [PHP_BINARY, '-r', 'echo $argv[1];', '--', $payload],
            cwd: $this->cwd,
            env: EnvPolicy::minimal(),
        );

        $this->assertTrue($result->succeeded(), $result->stderr);
        $this->assertSame($payload, trim($result->stdout));
    }

    #[Test]
    public function env_policy_minimal_filters_out_api_keys(): void
    {
        putenv('OPENAI_API_KEY=sk-fake-leaked');
        putenv('ANTHROPIC_API_KEY=sk-ant-fake-leaked');

        try {
            $result = $this->runner()->run(
                argv: [
                    PHP_BINARY,
                    '-r',
                    'echo (getenv("OPENAI_API_KEY") ?: "none")."|".(getenv("ANTHROPIC_API_KEY") ?: "none");',
                ],
                cwd: $this->cwd,
                env: EnvPolicy::minimal(),
            );

            $this->assertTrue($result->succeeded(), $result->stderr);
            $this->assertSame('none|none', trim($result->stdout));
        } finally {
            putenv('OPENAI_API_KEY');
            putenv('ANTHROPIC_API_KEY');
        }
    }

    #[Test]
    public function env_policy_for_ai_tool_only_passes_matching_credentials(): void
    {
        putenv('OPENAI_API_KEY=sk-openai');
        putenv('ANTHROPIC_API_KEY=sk-anthropic');
        putenv('GEMINI_API_KEY=sk-gemini');

        try {
            // Policy for 'claude' must pass ANTHROPIC_API_KEY but NOT OPENAI/GEMINI.
            $result = $this->runner()->run(
                argv: [
                    PHP_BINARY,
                    '-r',
                    'echo (getenv("ANTHROPIC_API_KEY") ?: "none")."|".'.
                    '(getenv("OPENAI_API_KEY") ?: "none")."|".'.
                    '(getenv("GEMINI_API_KEY") ?: "none");',
                ],
                cwd: $this->cwd,
                env: EnvPolicy::forAiTool('claude'),
            );

            $this->assertTrue($result->succeeded(), $result->stderr);
            $this->assertSame('sk-anthropic|none|none', trim($result->stdout));
        } finally {
            putenv('OPENAI_API_KEY');
            putenv('ANTHROPIC_API_KEY');
            putenv('GEMINI_API_KEY');
        }
    }

    #[Test]
    public function env_policy_strips_ai_nesting_markers(): void
    {
        putenv('CLAUDECODE=1');
        putenv('CLAUDE_CODE_ENTRYPOINT=cli');

        try {
            $result = $this->runner()->run(
                argv: [
                    PHP_BINARY,
                    '-r',
                    'echo (getenv("CLAUDECODE") ?: "none")."|".(getenv("CLAUDE_CODE_ENTRYPOINT") ?: "none");',
                ],
                cwd: $this->cwd,
                env: EnvPolicy::forAiTool('claude'),
            );

            $this->assertTrue($result->succeeded(), $result->stderr);
            $this->assertSame('none|none', trim($result->stdout));
        } finally {
            putenv('CLAUDECODE');
            putenv('CLAUDE_CODE_ENTRYPOINT');
        }
    }

    #[Test]
    public function large_stderr_does_not_deadlock(): void
    {
        // Emit ~256KB to stderr — well over the ~64KB pipe buffer. A naive
        // "read stdout first, then stderr" implementation would block here.
        $result = $this->runner()->run(
            argv: [
                PHP_BINARY,
                '-r',
                'fwrite(STDERR, str_repeat("X", 262144)); echo "done";',
            ],
            cwd: $this->cwd,
            env: EnvPolicy::minimal(),
            timeout: 10,
        );

        $this->assertFalse($result->timedOut, 'process must finish without timing out');
        $this->assertTrue($result->succeeded(), 'exit code must be 0');
        $this->assertSame('done', trim($result->stdout));
        $this->assertSame(262144, strlen($result->stderr));
    }

    #[Test]
    public function timeout_terminates_long_running_process(): void
    {
        $result = $this->runner()->run(
            argv: [PHP_BINARY, '-r', 'sleep(10); echo "late";'],
            cwd: $this->cwd,
            env: EnvPolicy::minimal(),
            timeout: 1,
        );

        $this->assertTrue($result->timedOut);
        $this->assertSame(124, $result->exitCode);
        $this->assertLessThan(5, $result->durationSeconds);
        $this->assertStringNotContainsString('late', $result->stdout);
    }

    #[Test]
    public function stdin_is_delivered_to_child_process(): void
    {
        $result = $this->runner()->run(
            argv: [PHP_BINARY, '-r', 'echo fgets(STDIN);'],
            cwd: $this->cwd,
            env: EnvPolicy::minimal(),
            stdin: "secret-from-stdin\n",
        );

        $this->assertTrue($result->succeeded(), $result->stderr);
        $this->assertSame('secret-from-stdin', trim($result->stdout));
    }

    #[Test]
    public function nonexistent_binary_returns_127(): void
    {
        $result = $this->runner()->run(
            argv: ['this-binary-definitely-does-not-exist-'.uniqid()],
            cwd: $this->cwd,
            env: EnvPolicy::minimal(),
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(127, $result->exitCode);
    }
}
