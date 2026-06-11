<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Immutable result of probing one AI CLI tool's authentication state (issue #23).
 *
 * Carries enough context for `tessera doctor` to render the right line:
 *   - Authenticated → green check with version
 *   - Unverified    → green check with "(login not verified)" annotation
 *   - LoggedOut     → yellow warning with the login command to run
 */
final readonly class AuthProbeResult
{
    public function __construct(
        public string $toolName,
        public string $version,
        public AuthProbeStatus $status,
        public string $loginCommand,
    ) {}

    public static function authenticated(string $toolName, string $version, string $loginCommand): self
    {
        return new self($toolName, $version, AuthProbeStatus::Authenticated, $loginCommand);
    }

    public static function loggedOut(string $toolName, string $version, string $loginCommand): self
    {
        return new self($toolName, $version, AuthProbeStatus::LoggedOut, $loginCommand);
    }

    public static function unverified(string $toolName, string $version, string $loginCommand): self
    {
        return new self($toolName, $version, AuthProbeStatus::Unverified, $loginCommand);
    }

    /**
     * Whether this tool counts toward "at least one usable AI tool" for the
     * doctor exit code. Delegates to the status; only a conclusive logged-out
     * result is unusable.
     */
    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }
}
