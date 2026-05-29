<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Explicit environment-variable policy for subprocess execution.
 *
 * The installer spawns three categories of subprocesses:
 *
 *   1. AI CLI tools (claude / gemini / codex) — these need the user's API
 *      credentials for the respective provider, plus PATH and locale.
 *   2. Build tools (composer, npm, php artisan, git, psql, mysql) — these need
 *      PATH and the project directory, but should NOT inherit cross-provider
 *      API keys.
 *   3. Detection probes (tool --version) — need only PATH.
 *
 * Without this policy, every subprocess inherited the full parent env,
 * leaking OPENAI_API_KEY to the composer process, GITHUB_TOKEN to the MySQL
 * CLI, CI tokens to AI tools, etc. Subprocesses should only see what they need.
 */
final class EnvPolicy
{
    /**
     * Variables every subprocess needs to function at all.
     * PATH and OS-locator vars, plus locale.
     */
    private const BASE_ALLOWLIST = [
        'PATH',
        'HOME',
        'HOMEDRIVE',
        'HOMEPATH',
        'USERPROFILE',
        'SYSTEMROOT',
        'WINDIR',
        'TEMP',
        'TMP',
        'TMPDIR',
        'LANG',
        'LC_ALL',
        'LC_CTYPE',
        'COMSPEC',
        'PATHEXT',
        'APPDATA',
        'LOCALAPPDATA',
        'PROGRAMDATA',
        'PROGRAMFILES',
        'PROGRAMFILES(X86)',
        'PROGRAMW6432',
        'SHELL',
        'TERM',
        'USER',
        'USERNAME',
        'USERDOMAIN',
        'LOGNAME',
        'PWD',
        // Network reachability — corporate proxies and custom CA bundles. These
        // are configuration, not credentials, and an AI CLI (or any subprocess)
        // that needs to reach the network behind a proxy will hang without them.
        // The legacy denylist passed these through implicitly; the allowlist must
        // keep doing so or proxied/corp environments regress.
        'HTTP_PROXY',
        'HTTPS_PROXY',
        'NO_PROXY',
        'http_proxy',
        'https_proxy',
        'no_proxy',
        'ALL_PROXY',
        'all_proxy',
        'SSL_CERT_FILE',
        'SSL_CERT_DIR',
        'NODE_EXTRA_CA_CERTS',
        // Node toolchain locators — the AI CLIs (claude/gemini) are Node binaries
        // and may rely on these to find their runtime/modules, especially under
        // nvm-managed installs.
        'NODE_PATH',
        'NPM_CONFIG_PREFIX',
        'NVM_HOME',
        'NVM_SYMLINK',
        'NVM_DIR',
    ];

    /**
     * AI-specific credentials. Only the matching AI tool should see these.
     * Keyed by AiTool::name().
     *
     * @var array<string, array<int, string>>
     */
    private const AI_CREDENTIALS = [
        'claude' => [
            'ANTHROPIC_API_KEY',
            'ANTHROPIC_AUTH_TOKEN',
            'CLAUDE_CONFIG_DIR',
            // Endpoint/model overrides — provider-scoped configuration that
            // points the CLI at a proxy/gateway or pins a model. Passing these
            // only to the matching provider cannot leak across providers.
            'ANTHROPIC_BASE_URL',
            'ANTHROPIC_MODEL',
        ],
        'gemini' => [
            'GOOGLE_API_KEY',
            'GEMINI_API_KEY',
            'GOOGLE_APPLICATION_CREDENTIALS',
            'GOOGLE_CLOUD_PROJECT',
            'GOOGLE_CLOUD_LOCATION',
            'GCLOUD_PROJECT',
            'CLOUDSDK_CONFIG',
        ],
        'codex' => [
            'OPENAI_API_KEY',
            'OPENAI_ORG_ID',
            'OPENAI_PROJECT',
            'OPENAI_BASE_URL',
            'CODEX_HOME',
        ],
    ];

    /**
     * Passthrough vars that are safe to hand to an AI CLI child: they are NOT
     * credentials (nor handles that grant access to credentials), and an AI CLI
     * — itself a Node/JS binary — plausibly reads them (config/cache dirs, Node
     * runtime tuning, the user's Tessera flags). These flow into BOTH the
     * AI-tool policy and the build-tool policy.
     *
     * The discriminator for membership here: (a) the value is not a secret and
     * does not unlock one, AND (b) an AI CLI plausibly consults it. Anything that
     * fails either test belongs in BUILD_ONLY_PASSTHROUGH below.
     */
    private const AI_SAFE_PASSTHROUGH = [
        'NODE_OPTIONS',
        'XDG_CONFIG_HOME',
        'XDG_DATA_HOME',
        'XDG_CACHE_HOME',
        // Respect user's Tessera configuration.
        'TESSERA_SAFE_AI',
        'TESSERA_AI_TIMEOUT',
        'TESSERA_TOOL_PREFERENCE',
        'TESSERA_TOOL_EXCLUDE',
        'TESSERA_CLAUDE_PLAN',
        'TESSERA_CODEX_PLAN',
        'TESSERA_GEMINI_PLAN',
    ];

    /**
     * Passthrough vars that must reach build/shell tools (composer, npm, git,
     * php) but must NEVER reach an AI CLI child. Two reasons a var lives here:
     *
     *   - It is a credential or a handle that grants credential access:
     *       COMPOSER_AUTH    — frequently holds a GitHub OAuth token / private-
     *                          repo HTTP basic-auth JSON.
     *       SSH_AUTH_SOCK    — the ssh-agent socket → live signing access to the
     *                          user's loaded SSH private keys.
     *       SSH_AGENT_PID    — part of the same ssh-agent capability surface.
     *       GIT_SSH /
     *       GIT_SSH_COMMAND  — can embed an identity file (`-i path`) or an
     *                          arbitrary wrapper command.
     *   - It is build-tool-specific configuration an AI CLI has no use for
     *     (COMPOSER_*, PHPRC, PHP_INI_SCAN_DIR) and that only widens the AI
     *     child's view of the host for no benefit.
     *
     * Keeping these out of forAiTool() is the credential-leak fix for issue #4:
     * an AI child no longer inherits Git/SSH/Composer credentials.
     */
    private const BUILD_ONLY_PASSTHROUGH = [
        'COMPOSER_HOME',
        'COMPOSER_AUTH',
        'COMPOSER_CACHE_DIR',
        'COMPOSER_NO_INTERACTION',
        'COMPOSER_MEMORY_LIMIT',
        'GIT_SSH',
        'GIT_SSH_COMMAND',
        'SSH_AUTH_SOCK',
        'SSH_AGENT_PID',
        'PHP_INI_SCAN_DIR',
        'PHPRC',
        // npm install-time configuration: the registry URL can embed an auth
        // token and is only consulted by `npm install`, never by an AI CLI at
        // prompt-execution time. (NPM_CONFIG_PREFIX stays in BASE_ALLOWLIST — it
        // is a binary locator, and NODE_OPTIONS stays AI-safe — it is runtime.)
        'NPM_CONFIG_REGISTRY',
        'NPM_CONFIG_CACHE',
    ];

    /**
     * AI-nesting markers that MUST be stripped so spawned Claude/Codex don't
     * refuse to run inside a parent Claude/Codex session.
     */
    private const NESTING_MARKERS = [
        'CLAUDECODE',
        'CLAUDE_CODE',
        'CLAUDE_CODE_SSE_PORT',
        'CLAUDE_CODE_ENTRYPOINT',
        'CLAUDECODE_ENTRYPOINT',
        'VIPSHOME',
    ];

    /** @var array<string> */
    private array $allowlist;

    /** @var array<string, string> Explicit extras merged into the filtered env. */
    private array $extras = [];

    /**
     * @param array<string> $allowlist  Exact env var names this policy will pass through.
     */
    private function __construct(array $allowlist)
    {
        // De-duplicate and uppercase for Windows-case-insensitive matching.
        $this->allowlist = array_values(array_unique(array_map('strtoupper', $allowlist)));
    }

    /**
     * Return a copy of this policy with additional env vars that will be
     * injected into the subprocess regardless of the parent process's env.
     *
     * Use for values the installer itself generates — DB passwords via
     * PGPASSWORD / MYSQL_PWD, per-subprocess temp paths, etc. These do NOT
     * come from the parent env at all.
     *
     * @param array<string, string> $extras
     */
    public function withExtra(array $extras): self
    {
        $clone = clone $this;
        foreach ($extras as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new \InvalidArgumentException('EnvPolicy::withExtra expects array<string, string>.');
            }
            $clone->extras[$key] = $value;
        }

        return $clone;
    }

    /**
     * Minimal policy — PATH + locale only. Use for `--version` probes and other
     * subprocesses that should never see credentials.
     */
    public static function minimal(): self
    {
        return new self(self::BASE_ALLOWLIST);
    }

    /**
     * Build-tool policy — base + AI-safe passthrough + build-only passthrough.
     * Use for `composer install`, `npm run build`, `php artisan`, `git commit`.
     * Carries the build/shell credentials (COMPOSER_AUTH, ssh-agent, GIT_SSH*)
     * those tools legitimately need. Notably does NOT include AI provider keys.
     */
    public static function buildTool(): self
    {
        return new self(array_merge(
            self::BASE_ALLOWLIST,
            self::AI_SAFE_PASSTHROUGH,
            self::BUILD_ONLY_PASSTHROUGH,
        ));
    }

    /**
     * AI-tool policy — base + AI-safe passthrough + credentials for the named
     * AI only. Other providers' keys are filtered out, and so are the build/
     * shell credentials in BUILD_ONLY_PASSTHROUGH (COMPOSER_AUTH, ssh-agent,
     * GIT_SSH*): an AI CLI child must see ONLY its own provider's credentials.
     */
    public static function forAiTool(string $toolName): self
    {
        $credentials = self::AI_CREDENTIALS[$toolName] ?? [];

        return new self(array_merge(
            self::BASE_ALLOWLIST,
            self::AI_SAFE_PASSTHROUGH,
            $credentials,
        ));
    }

    /**
     * Apply the policy to the current process env, returning the filtered
     * env suitable for proc_open().
     *
     * @return array<string, string>
     */
    public function apply(): array
    {
        $sourceRaw = getenv();
        if (! is_array($sourceRaw)) {
            return [];
        }

        // Normalise keys to uppercase for Windows-style matching while
        // preserving the original case for the spawned process.
        $result = [];
        foreach ($sourceRaw as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            $upper = strtoupper($key);

            // Strip AI-nesting markers unconditionally.
            if (in_array($upper, self::NESTING_MARKERS, true)) {
                continue;
            }

            if (in_array($upper, $this->allowlist, true)) {
                $result[$key] = $value;
            }
        }

        // Extras override parent env — intentional, they're the caller's
        // explicit value (e.g., PGPASSWORD set by DB configurator).
        foreach ($this->extras as $key => $value) {
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @return array<string>  Uppercase allowlist for inspection/testing.
     */
    public function allowlist(): array
    {
        return $this->allowlist;
    }
}
