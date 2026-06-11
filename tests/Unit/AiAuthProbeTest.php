<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiAuthProbe;
use Tessera\Installer\AuthProbeStatus;
use Tessera\Installer\CommandExecutor;
use Tessera\Installer\CommandResult;
use Tessera\Installer\EnvPolicy;

/**
 * Authentication probes for `tessera doctor` (issue #23).
 *
 * The real probes shell out to `claude auth status --json` / `codex login
 * status`; here we inject a CommandExecutor double (same seam used by
 * ConsoleCommandExecutionTest and the stack-loop feature tests) so no real
 * process is spawned. We assert both the classification (authenticated /
 * logged-out / unverified) AND that each probe shells out with the correct
 * argv and the provider's own credential policy — auth probes must carry
 * credentials (EnvPolicy::forAiTool), unlike the credential-less detection
 * probe (EnvPolicy::minimal).
 */
final class AiAuthProbeTest extends TestCase
{
    #[Test]
    public function claude_logged_in_json_is_authenticated(): void
    {
        $executor = $this->executorReturning(new CommandResult(
            exitCode: 0,
            stdout: '{"loggedIn": true, "email": "user@example.com"}',
            stderr: '',
            timedOut: false,
            durationSeconds: 0.05,
        ));

        $result = (new AiAuthProbe($executor))->probe('claude', '2.1.173');

        $this->assertSame(AuthProbeStatus::Authenticated, $result->status);
        $this->assertTrue($result->isUsable());
        $this->assertSame('claude', $result->toolName);
        $this->assertSame('2.1.173', $result->version);
        $this->assertSame('claude auth login', $result->loginCommand);

        // Correct command + credential-bearing env (NOT minimal).
        $this->assertSame(['claude', 'auth', 'status', '--json'], $executor->argv);
        $this->assertInstanceOf(EnvPolicy::class, $executor->env);
        $this->assertSame(EnvPolicy::forAiTool('claude')->allowlist(), $executor->env->allowlist());
        $this->assertSame(10, $executor->timeout);
    }

    #[Test]
    public function claude_logged_out_json_is_logged_out(): void
    {
        // `claude auth status --json` exits 0 even when logged out, so the
        // classification must come from the JSON body, not the exit code.
        $executor = $this->executorReturning(new CommandResult(
            exitCode: 0,
            stdout: '{"loggedIn": false}',
            stderr: '',
            timedOut: false,
            durationSeconds: 0.05,
        ));

        $result = (new AiAuthProbe($executor))->probe('claude', '2.1.173');

        $this->assertSame(AuthProbeStatus::LoggedOut, $result->status);
        $this->assertFalse($result->isUsable());
        $this->assertSame('claude auth login', $result->loginCommand);
    }

    #[Test]
    public function claude_non_json_output_is_unverified(): void
    {
        // An older CLI without `auth status --json` prints non-JSON / help text.
        // We must not guess "logged out" — annotate as unverified instead.
        $executor = $this->executorReturning(new CommandResult(
            exitCode: 0,
            stdout: "Usage: claude auth status\nUnknown option --json",
            stderr: '',
            timedOut: false,
            durationSeconds: 0.05,
        ));

        $result = (new AiAuthProbe($executor))->probe('claude', '2.1.173');

        $this->assertSame(AuthProbeStatus::Unverified, $result->status);
        $this->assertTrue($result->isUsable());
    }

    #[Test]
    public function claude_json_without_logged_in_key_is_unverified(): void
    {
        $executor = $this->executorReturning(new CommandResult(
            exitCode: 0,
            stdout: '{"email": "user@example.com"}',
            stderr: '',
            timedOut: false,
            durationSeconds: 0.05,
        ));

        $result = (new AiAuthProbe($executor))->probe('claude', '2.1.173');

        $this->assertSame(AuthProbeStatus::Unverified, $result->status);
    }

    #[Test]
    public function claude_binary_not_found_is_unverified(): void
    {
        $executor = $this->executorReturning(new CommandResult(
            exitCode: 127,
            stdout: '',
            stderr: 'Failed to start: claude',
            timedOut: false,
            durationSeconds: 0.0,
        ));

        $result = (new AiAuthProbe($executor))->probe('claude', '2.1.173');

        $this->assertSame(AuthProbeStatus::Unverified, $result->status);
        $this->assertTrue($result->isUsable());
    }

    #[Test]
    public function claude_timeout_is_unverified(): void
    {
        $executor = $this->executorReturning(new CommandResult(
            exitCode: 124,
            stdout: '',
            stderr: '',
            timedOut: true,
            durationSeconds: 10.0,
        ));

        $result = (new AiAuthProbe($executor))->probe('claude', '2.1.173');

        $this->assertSame(AuthProbeStatus::Unverified, $result->status);
    }

    #[Test]
    public function codex_exit_zero_is_authenticated(): void
    {
        $executor = $this->executorReturning(new CommandResult(
            exitCode: 0,
            stdout: 'Logged in using ChatGPT',
            stderr: '',
            timedOut: false,
            durationSeconds: 0.05,
        ));

        $result = (new AiAuthProbe($executor))->probe('codex', '0.139.0');

        $this->assertSame(AuthProbeStatus::Authenticated, $result->status);
        $this->assertSame('codex login', $result->loginCommand);

        $this->assertSame(['codex', 'login', 'status'], $executor->argv);
        $this->assertInstanceOf(EnvPolicy::class, $executor->env);
        $this->assertSame(EnvPolicy::forAiTool('codex')->allowlist(), $executor->env->allowlist());
        $this->assertSame(10, $executor->timeout);
    }

    #[Test]
    public function codex_nonzero_exit_is_logged_out(): void
    {
        $executor = $this->executorReturning(new CommandResult(
            exitCode: 1,
            stdout: '',
            stderr: 'Not logged in',
            timedOut: false,
            durationSeconds: 0.05,
        ));

        $result = (new AiAuthProbe($executor))->probe('codex', '0.139.0');

        $this->assertSame(AuthProbeStatus::LoggedOut, $result->status);
        $this->assertFalse($result->isUsable());
        $this->assertSame('codex login', $result->loginCommand);
    }

    #[Test]
    public function codex_binary_not_found_is_unverified(): void
    {
        $executor = $this->executorReturning(new CommandResult(
            exitCode: 127,
            stdout: '',
            stderr: 'Failed to start: codex',
            timedOut: false,
            durationSeconds: 0.0,
        ));

        $result = (new AiAuthProbe($executor))->probe('codex', '0.139.0');

        $this->assertSame(AuthProbeStatus::Unverified, $result->status);
    }

    #[Test]
    public function gemini_is_always_unverified_without_running_a_probe(): void
    {
        // No documented auth-safe status command — gemini is reported unverified
        // and the probe must NOT shell out at all.
        $executor = $this->recordingExecutor();

        $result = (new AiAuthProbe($executor))->probe('gemini', '1.0.0');

        $this->assertSame(AuthProbeStatus::Unverified, $result->status);
        $this->assertTrue($result->isUsable());
        $this->assertSame('gemini', $result->loginCommand);
        $this->assertNull($executor->argv, 'gemini probe must not spawn a subprocess');
    }

    #[Test]
    public function unknown_tool_is_unverified_without_running_a_probe(): void
    {
        $executor = $this->recordingExecutor();

        $result = (new AiAuthProbe($executor))->probe('totally-unknown', '9.9.9');

        $this->assertSame(AuthProbeStatus::Unverified, $result->status);
        $this->assertSame('totally-unknown', $result->loginCommand);
        $this->assertNull($executor->argv);
    }

    /**
     * A recording CommandExecutor that captures the last call and returns the
     * given result.
     */
    private function executorReturning(CommandResult $result): CommandExecutor
    {
        return new class($result) implements CommandExecutor
        {
            /** @var array<int, string>|null */
            public ?array $argv = null;

            public ?EnvPolicy $env = null;

            public ?int $timeout = null;

            public function __construct(private readonly CommandResult $result) {}

            public function run(
                array $argv,
                string $cwd,
                ?EnvPolicy $env = null,
                ?string $stdin = null,
                ?int $timeout = null,
            ): CommandResult {
                $this->argv = $argv;
                $this->env = $env;
                $this->timeout = $timeout;

                return $this->result;
            }
        };
    }

    /**
     * A CommandExecutor that fails the test if it is ever invoked is overkill;
     * instead this records the call so a test can assert it was NOT made.
     */
    private function recordingExecutor(): CommandExecutor
    {
        return $this->executorReturning(new CommandResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            timedOut: false,
            durationSeconds: 0.0,
        ));
    }
}
