<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
