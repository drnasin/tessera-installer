<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiConfigIsolation;

/**
 * Behavioral-config isolation flags for AI subprocesses (issue #15).
 *
 * These tests pin the contract of the isolation helper directly: which CLI
 * flags each provider gets, the on-by-default behaviour, and the
 * TESSERA_ISOLATE_AI_CONFIG=0 opt-out. The flags themselves are auth-safe
 * (claude --safe-mode keeps OAuth/keychain; codex --ignore-user-config keeps
 * $CODEX_HOME auth) — verified manually against the installed CLIs and
 * documented in the PR; here we guard the construction contract.
 */
final class AiConfigIsolationTest extends TestCase
{
    /** @var string|false */
    private string|false $originalOptOut = false;

    protected function setUp(): void
    {
        $this->originalOptOut = getenv('TESSERA_ISOLATE_AI_CONFIG');
        // Default to a clean (unset) state so each test controls the flag.
        putenv('TESSERA_ISOLATE_AI_CONFIG');
    }

    protected function tearDown(): void
    {
        if ($this->originalOptOut === false) {
            putenv('TESSERA_ISOLATE_AI_CONFIG');
        } else {
            putenv('TESSERA_ISOLATE_AI_CONFIG='.$this->originalOptOut);
        }
    }

    #[Test]
    public function isolation_is_enabled_by_default(): void
    {
        $this->assertTrue(AiConfigIsolation::enabled());
    }

    #[Test]
    public function claude_gets_safe_mode_when_enabled(): void
    {
        // --safe-mode disables CLAUDE.md / skills / MCP / hooks but preserves
        // OAuth + keychain auth (unlike --bare).
        $this->assertSame(['--safe-mode'], AiConfigIsolation::argsFor('claude'));
    }

    #[Test]
    public function codex_gets_ignore_user_config_when_enabled(): void
    {
        $this->assertSame(['--ignore-user-config'], AiConfigIsolation::argsFor('codex'));
    }

    #[Test]
    public function gemini_gets_no_flags_no_documented_auth_safe_mechanism(): void
    {
        $this->assertSame([], AiConfigIsolation::argsFor('gemini'));
    }

    #[Test]
    public function unknown_tool_fails_open_with_no_flags(): void
    {
        // Never inject an unrecognised flag into an unknown CLI.
        $this->assertSame([], AiConfigIsolation::argsFor('totally-unknown'));
    }

    #[Test]
    public function opt_out_zero_disables_isolation_for_every_tool(): void
    {
        putenv('TESSERA_ISOLATE_AI_CONFIG=0');

        $this->assertFalse(AiConfigIsolation::enabled());
        $this->assertSame([], AiConfigIsolation::argsFor('claude'));
        $this->assertSame([], AiConfigIsolation::argsFor('codex'));
        $this->assertSame([], AiConfigIsolation::argsFor('gemini'));
    }

    #[Test]
    public function opt_out_false_disables_isolation(): void
    {
        putenv('TESSERA_ISOLATE_AI_CONFIG=false');

        $this->assertFalse(AiConfigIsolation::enabled());
        $this->assertSame([], AiConfigIsolation::argsFor('claude'));
    }

    #[Test]
    public function any_other_value_keeps_isolation_enabled(): void
    {
        putenv('TESSERA_ISOLATE_AI_CONFIG=1');

        $this->assertTrue(AiConfigIsolation::enabled());
        $this->assertSame(['--safe-mode'], AiConfigIsolation::argsFor('claude'));
    }
}
