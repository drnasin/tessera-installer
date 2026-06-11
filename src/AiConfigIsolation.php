<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Behavioral-config isolation for AI CLI subprocesses (issue #15).
 *
 * v3.11.4 `EnvPolicy` isolated *credentials* — each AI child sees only its own
 * provider's keys. It did NOT isolate *behavioral configuration*: an AI CLI
 * still discovered the user's personal instruction files (e.g. a global
 * `~/.claude/CLAUDE.md` saying "always reply in Croatian"). That made the
 * installer's English requirements interview come back in the user's language
 * and, more broadly, made AI output depend on each machine's unrelated personal
 * AI settings.
 *
 * This class is deliberately separate from `EnvPolicy`: that class filters the
 * child *environment* (an allowlist of env vars), whereas behavioral isolation
 * here is expressed as *CLI flags* added to the invocation. They are different
 * concerns and keeping them apart avoids overloading EnvPolicy's single
 * responsibility.
 *
 * ## Why CLI flags, not `CLAUDE_CONFIG_DIR`
 *
 * The issue's first suggestion — point `CLAUDE_CONFIG_DIR` at a disposable temp
 * directory — is unsafe on real installs: the `claude` CLI stores OAuth
 * credentials *inside* the config dir (`~/.claude/.credentials.json` on
 * Windows). Relocating the config dir to an empty directory orphans those
 * credentials and breaks authentication for everyone not using a raw
 * `ANTHROPIC_API_KEY`. So instead we use the CLI's own auth-preserving flags:
 *
 *   - claude:  `--safe-mode` — starts with all customizations disabled
 *              (CLAUDE.md, skills, plugins, hooks, MCP servers, commands,
 *              agents, output styles, ...). It does NOT touch auth: OAuth and
 *              keychain reads continue to work (unlike `--bare`, which forces
 *              ANTHROPIC_API_KEY and would break OAuth users).
 *   - codex:   `--ignore-user-config` — does not load `$CODEX_HOME/config.toml`
 *              (which can carry custom instructions); auth still uses
 *              `$CODEX_HOME`. This is *partial* isolation — it is not documented
 *              to suppress a global `AGENTS.md` — but it is the auth-safe
 *              mechanism the CLI exposes today.
 *   - gemini:  no auth-safe behavioral-isolation flag is currently documented,
 *              so no flags are added. This is a known gap, noted here so a
 *              future CLI flag can slot in without touching call sites.
 *
 * ## Scope: caller-opt-in, not global
 *
 * Isolation is applied ONLY where the installer needs deterministic,
 * machine-independent output and where there is no project-level configuration
 * to lose: the requirements interview / stack-selection prompts in
 * `NewCommand`, which run in the installer's own working directory *before the
 * generated project exists*. The actual build steps run inside the generated
 * project, where a project `CLAUDE.md` / `.ai/` instructions may legitimately
 * shape the build; `--safe-mode` would disable those too. Build/review calls
 * therefore do NOT request isolation — they pass through unchanged.
 *
 * Users can disable isolation entirely with `TESSERA_ISOLATE_AI_CONFIG=0` (the
 * escape hatch in case a future CLI change makes a flag break authentication on
 * some setup).
 */
final class AiConfigIsolation
{
    /**
     * Behavioral-isolation CLI flags per AI tool, applied when isolation is on.
     *
     * @var array<string, array<int, string>>
     */
    private const ISOLATION_FLAGS = [
        'claude' => ['--safe-mode'],
        'codex' => ['--ignore-user-config'],
        // gemini: no documented auth-safe flag — intentionally empty.
        'gemini' => [],
    ];

    /**
     * Whether behavioral-config isolation is enabled. On by default; users opt
     * out with TESSERA_ISOLATE_AI_CONFIG=0 if a flag ever breaks their auth.
     */
    public static function enabled(): bool
    {
        $value = getenv('TESSERA_ISOLATE_AI_CONFIG');

        return ! ($value === '0' || strtolower((string) $value) === 'false');
    }

    /**
     * CLI flags that isolate the given tool from the user's behavioral config.
     *
     * Returns an empty array when isolation is disabled, the tool has no
     * documented auth-safe flag (gemini), or the tool name is unknown
     * (fail-open to a normal invocation — never inject an unrecognised flag).
     *
     * @return array<int, string>
     */
    public static function argsFor(string $toolName): array
    {
        if (! self::enabled()) {
            return [];
        }

        return self::ISOLATION_FLAGS[$toolName] ?? [];
    }
}
