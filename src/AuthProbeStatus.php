<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Outcome of an AI CLI authentication probe (issue #23).
 *
 *   - Authenticated: the probe conclusively confirmed the user is logged in.
 *   - LoggedOut:     the probe conclusively confirmed the user is NOT logged in.
 *   - Unverified:    the probe could not determine the state (no status command,
 *                    unparseable output, binary missing, or timeout). Treated as
 *                    usable so doctor does not hard-fail on a probe gap.
 */
enum AuthProbeStatus
{
    case Authenticated;
    case LoggedOut;
    case Unverified;

    /**
     * Whether a tool in this state counts toward "at least one usable AI tool".
     * Only a conclusive logged-out result is unusable; unverified tools are
     * given the benefit of the doubt (the probe, not the tool, is the unknown).
     */
    public function isUsable(): bool
    {
        return $this !== self::LoggedOut;
    }
}
