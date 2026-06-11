<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Authentication probe for detected AI CLI tools (issue #23).
 *
 * `tessera doctor` previously verified only that the `claude`/`codex`/`gemini`
 * binaries exist and report a version (the `--version` detection probe, which
 * runs credential-less via EnvPolicy::minimal()). A user with an installed but
 * logged-out CLI got a fully green doctor, then failed minutes into their first
 * build when the first real AI call errored on missing credentials.
 *
 * This class adds a cheap, token-free auth/status probe per tool. It does NOT
 * send a prompt — it asks the CLI's own status subcommand whether the user is
 * authenticated.
 *
 * ## Why probes run with the user's REAL config (no behavioral isolation)
 *
 * Unlike AiConfigIsolation (issue #15) — which adds `--safe-mode` /
 * `--ignore-user-config` to deterministic interview calls — auth probes MUST
 * see the user's real credential store. Isolating the config dir (e.g. claude's
 * `--safe-mode` does not touch auth, but pointing CLAUDE_CONFIG_DIR elsewhere
 * would) would hide the OAuth/keychain credentials and always report
 * logged-out. So probes use the provider's own credentials via
 * EnvPolicy::forAiTool() and skip AiConfigIsolation entirely.
 *
 * ## Probe mechanism per tool (verified against installed CLIs)
 *
 *   - claude (2.1.173):  `claude auth status --json` → exit 0 in BOTH states,
 *                        with a JSON body `{"loggedIn": true|false, ...}`. The
 *                        exit code does not discriminate, so we parse the JSON
 *                        `loggedIn` field. Older CLIs without the subcommand or
 *                        the --json flag yield a non-JSON body / non-zero exit →
 *                        Unverified (never a false "logged out").
 *                        Login command: `claude auth login`.
 *   - codex (0.139.0):   `codex login status` → exit 0 when logged in
 *                        ("Logged in using ChatGPT"), non-zero when logged out.
 *                        We trust the exit code here; 127/timeout → Unverified.
 *                        Login command: `codex login`.
 *   - gemini:            No auth-safe status subcommand is documented/verified,
 *                        so gemini is always reported Unverified. This is a
 *                        known gap noted here so a future CLI command can slot
 *                        in without touching call sites.
 *                        Login command: `gemini` (interactive auth on first run).
 */
final class AiAuthProbe
{
    /**
     * Per-probe timeout. A status check hits a local credential store (and at
     * most a quick token validation) — it must stay fast so doctor does too.
     */
    private const PROBE_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly CommandExecutor $executor = new CommandRunner(self::PROBE_TIMEOUT_SECONDS),
    ) {}

    /**
     * Whether the AI-tools section should FAIL `tessera doctor` (exit 1).
     *
     * Two cases are deliberately distinct (issue #23 + approval correction):
     *
     *   - No AI tool installed at all (`$probeResults === []`): NOT a failure.
     *     This is informational — doctor prints "No AI tools found!" and stays
     *     exit 0, preserving the long-standing behaviour the CI CLI-smoke test
     *     relies on (runners have zero AI tools). A user simply hasn't
     *     installed one yet.
     *   - One or more tools installed but EVERY one is conclusively logged out:
     *     this IS a failure (exit 1). It is the regression issue #23 fixes — a
     *     green doctor followed by a build that dies on the first AI call. It
     *     cannot occur on a bare CI runner (no tools → first case).
     *
     * Unverified tools count as usable, so a probe gap never trips this.
     *
     * @param array<int, AuthProbeResult> $probeResults Probe results for the detected tools.
     */
    public static function allInstalledToolsLoggedOut(array $probeResults): bool
    {
        if ($probeResults === []) {
            return false;
        }

        foreach ($probeResults as $result) {
            if ($result->isUsable()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Probe a single detected AI tool's authentication state.
     *
     * @param string $toolName One of claude / codex / gemini (the names from AiTool::tools()).
     * @param string $version  The already-detected version string, echoed back in the result.
     */
    public function probe(string $toolName, string $version): AuthProbeResult
    {
        return match ($toolName) {
            'claude' => $this->probeClaude($version),
            'codex' => $this->probeCodex($version),
            // gemini and any unknown tool: no verifiable status mechanism.
            default => AuthProbeResult::unverified($toolName, $version, $this->loginCommandFor($toolName)),
        };
    }

    private function probeClaude(string $version): AuthProbeResult
    {
        $loginCommand = $this->loginCommandFor('claude');

        $result = $this->executor->run(
            argv: ['claude', 'auth', 'status', '--json'],
            cwd: (string) getcwd(),
            env: EnvPolicy::forAiTool('claude'),
            stdin: null,
            timeout: self::PROBE_TIMEOUT_SECONDS,
        );

        // Binary vanished / timed out / errored before producing JSON: we can't
        // tell, so don't claim logged-out. Annotate honestly as unverified.
        if ($result->exitCode === 127 || $result->timedOut) {
            return AuthProbeResult::unverified('claude', $version, $loginCommand);
        }

        $decoded = json_decode(trim($result->stdout), true);

        // No parseable {"loggedIn": bool}: an older CLI without `auth status
        // --json`, or an unexpected shape. Fall back to unverified rather than
        // guessing — exit code alone does not discriminate the two states here.
        if (! is_array($decoded) || ! array_key_exists('loggedIn', $decoded)) {
            return AuthProbeResult::unverified('claude', $version, $loginCommand);
        }

        return $decoded['loggedIn'] === true
            ? AuthProbeResult::authenticated('claude', $version, $loginCommand)
            : AuthProbeResult::loggedOut('claude', $version, $loginCommand);
    }

    private function probeCodex(string $version): AuthProbeResult
    {
        $loginCommand = $this->loginCommandFor('codex');

        $result = $this->executor->run(
            argv: ['codex', 'login', 'status'],
            cwd: (string) getcwd(),
            env: EnvPolicy::forAiTool('codex'),
            stdin: null,
            timeout: self::PROBE_TIMEOUT_SECONDS,
        );

        // Binary vanished / timed out: can't tell → unverified.
        if ($result->exitCode === 127 || $result->timedOut) {
            return AuthProbeResult::unverified('codex', $version, $loginCommand);
        }

        // `codex login status` exits 0 when authenticated, non-zero when not.
        return $result->exitCode === 0
            ? AuthProbeResult::authenticated('codex', $version, $loginCommand)
            : AuthProbeResult::loggedOut('codex', $version, $loginCommand);
    }

    private function loginCommandFor(string $toolName): string
    {
        return match ($toolName) {
            'claude' => 'claude auth login',
            'codex' => 'codex login',
            // gemini authenticates on first interactive run; there is no
            // dedicated login subcommand to point users at.
            'gemini' => 'gemini',
            default => $toolName,
        };
    }
}
