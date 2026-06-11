<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiAuthProbe;
use Tessera\Installer\AuthProbeResult;
use Tessera\Installer\AuthProbeStatus;

/**
 * Usability semantics that drive the `tessera doctor` exit code (issue #23).
 *
 * Doctor exits 0 when ≥1 AI tool is usable, 1 when none are. "Usable" is
 * defined here: authenticated and unverified count; only a conclusive
 * logged-out result does not. These tests pin that contract directly so the
 * exit-code matrix in bin/tessera rests on a tested primitive.
 */
final class AuthProbeResultTest extends TestCase
{
    #[Test]
    public function authenticated_is_usable(): void
    {
        $this->assertTrue(AuthProbeStatus::Authenticated->isUsable());
        $this->assertTrue(
            AuthProbeResult::authenticated('claude', '2.1.173', 'claude auth login')->isUsable(),
        );
    }

    #[Test]
    public function unverified_is_usable(): void
    {
        // The probe is the unknown, not the tool — give it the benefit of the
        // doubt so a probe gap never hard-fails doctor.
        $this->assertTrue(AuthProbeStatus::Unverified->isUsable());
        $this->assertTrue(
            AuthProbeResult::unverified('gemini', '1.0.0', 'gemini')->isUsable(),
        );
    }

    #[Test]
    public function logged_out_is_not_usable(): void
    {
        $this->assertFalse(AuthProbeStatus::LoggedOut->isUsable());
        $this->assertFalse(
            AuthProbeResult::loggedOut('codex', '0.139.0', 'codex login')->isUsable(),
        );
    }

    #[Test]
    public function factories_preserve_tool_version_and_login_command(): void
    {
        $result = AuthProbeResult::loggedOut('codex', '0.139.0', 'codex login');

        $this->assertSame('codex', $result->toolName);
        $this->assertSame('0.139.0', $result->version);
        $this->assertSame('codex login', $result->loginCommand);
        $this->assertSame(AuthProbeStatus::LoggedOut, $result->status);
    }

    /**
     * The exit-code rule expressed as a unit: doctor is green iff at least one
     * probed tool is usable.
     */
    #[Test]
    public function at_least_one_usable_tool_keeps_doctor_green(): void
    {
        $mixed = [
            AuthProbeResult::loggedOut('claude', '2.1.173', 'claude auth login'),
            AuthProbeResult::authenticated('codex', '0.139.0', 'codex login'),
        ];
        $this->assertGreaterThan(0, $this->countUsable($mixed));

        $allLoggedOut = [
            AuthProbeResult::loggedOut('claude', '2.1.173', 'claude auth login'),
            AuthProbeResult::loggedOut('codex', '0.139.0', 'codex login'),
        ];
        $this->assertSame(0, $this->countUsable($allLoggedOut));
    }

    /** @param array<int, AuthProbeResult> $results */
    private function countUsable(array $results): int
    {
        return count(array_filter($results, fn (AuthProbeResult $r): bool => $r->isUsable()));
    }

    /**
     * The doctor AI-section exit verdict. The "no AI tool installed at all"
     * case must NOT fail doctor (exit 0) — this is the long-standing behaviour
     * the CI CLI-smoke step depends on (runners have zero AI tools). Only a
     * present-but-all-logged-out fleet fails (exit 1).
     */
    #[Test]
    public function no_tools_installed_does_not_fail_doctor(): void
    {
        // Empty = "No AI tools found!" → informational, stays exit 0.
        $this->assertFalse(AiAuthProbe::allInstalledToolsLoggedOut([]));
    }

    #[Test]
    public function all_installed_tools_logged_out_fails_doctor(): void
    {
        $this->assertTrue(AiAuthProbe::allInstalledToolsLoggedOut([
            AuthProbeResult::loggedOut('claude', '2.1.173', 'claude auth login'),
            AuthProbeResult::loggedOut('codex', '0.139.0', 'codex login'),
        ]));
    }

    #[Test]
    public function one_authenticated_tool_keeps_doctor_green(): void
    {
        $this->assertFalse(AiAuthProbe::allInstalledToolsLoggedOut([
            AuthProbeResult::loggedOut('claude', '2.1.173', 'claude auth login'),
            AuthProbeResult::authenticated('codex', '0.139.0', 'codex login'),
        ]));
    }

    #[Test]
    public function one_unverified_tool_keeps_doctor_green(): void
    {
        // A probe gap (unverified) must never trip the exit-1 verdict.
        $this->assertFalse(AiAuthProbe::allInstalledToolsLoggedOut([
            AuthProbeResult::loggedOut('claude', '2.1.173', 'claude auth login'),
            AuthProbeResult::unverified('gemini', '1.0.0', 'gemini'),
        ]));
    }
}
