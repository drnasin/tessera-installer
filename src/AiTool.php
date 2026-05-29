<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Detects and executes AI CLI tools without any framework dependency.
 */
class AiTool
{
    /**
     * Tool configuration is built per-call (not a class const) so runtime env flags
     * — notably TESSERA_SAFE_AI=1 which strips Claude's --dangerously-skip-permissions —
     * take effect even if the caller sets them via putenv() mid-process. This is a
     * deliberate choice over a class const; the marginal cost of rebuilding a small array
     * on each detect*() call is negligible compared to the flexibility it provides.
     *
     * TESSERA_SAFE_AI today affects ONLY Claude. Codex and Gemini have their own
     * permission models (codex exec's sandbox, Gemini's default approval flow)
     * which Tessera does not currently configure. Extending SAFE_AI coverage to
     * those CLIs is a planned improvement — see the AI Permission Mode section
     * of README.md.
     *
     * @return array<string, array{binary: string, detect: string, execute: array<int, string>, stdin: bool}>
     */
    private static function tools(): array
    {
        // Claude Code normally asks the user to approve each tool call.
        // The installer runs AI non-interactively as a subprocess, so the
        // default must be --dangerously-skip-permissions or AI hangs on first
        // file write. Power users can set TESSERA_SAFE_AI=1 to opt out; the
        // installer will then fail loudly if Claude tries to do anything that
        // needs approval, which is the correct behaviour for that mode.
        $safeAi = getenv('TESSERA_SAFE_AI');
        $claudeArgs = ['claude', '-p', '--output-format', 'text', '--verbose'];
        if ($safeAi === false || $safeAi === '' || $safeAi === '0') {
            array_splice($claudeArgs, 2, 0, ['--dangerously-skip-permissions']);
        }

        return [
            'claude' => [
                'binary' => 'claude',
                'detect' => 'claude --version',
                'execute' => $claudeArgs,
                'stdin' => true,
            ],
            'gemini' => [
                'binary' => 'gemini',
                'detect' => 'gemini --version',
                'execute' => ['gemini'],
                'stdin' => false,
            ],
            'codex' => [
                'binary' => 'codex',
                'detect' => 'codex --version',
                'execute' => ['codex', 'exec', '--skip-git-repo-check'],
                'stdin' => false,
            ],
        ];
    }

    private string $name;

    /** @var array<string, mixed> */
    private array $config;

    private ?string $version;

    private function __construct(string $name, array $config, ?string $version)
    {
        $this->name = $name;
        $this->config = $config;
        $this->version = $version;
    }

    /**
     * Create a tool instance for testing without system detection.
     *
     * @internal For testing only.
     */
    public static function fake(string $name, ?string $version = 'fake-1.0'): static
    {
        $tools = self::tools();
        $config = $tools[$name] ?? $tools['claude'];

        return new static($name, $config, $version);
    }

    /**
     * Detect the best available AI tool.
     */
    public static function detect(): ?self
    {
        foreach (self::tools() as $name => $config) {
            $version = self::checkAvailable($config['detect']);

            if ($version !== null) {
                return new self($name, $config, $version);
            }
        }

        return null;
    }

    /**
     * Detect all available tools (info only — for display).
     *
     * @return array<string, array{name: string, version: string}>
     */
    public static function detectAll(): array
    {
        $available = [];

        foreach (self::tools() as $name => $config) {
            $version = self::checkAvailable($config['detect']);

            if ($version !== null) {
                $available[$name] = ['name' => $name, 'version' => $version];
            }
        }

        return $available;
    }

    /**
     * Detect all available tools as executable instances.
     *
     * @return array<string, self>
     */
    public static function detectAllInstances(): array
    {
        $available = [];

        foreach (self::tools() as $name => $config) {
            $version = self::checkAvailable($config['detect']);

            if ($version !== null) {
                $available[$name] = new self($name, $config, $version);
            }
        }

        return $available;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    /**
     * Execute a prompt and return the output.
     */
    public function execute(string $prompt, string $workingDir, int $timeout = 600, ?string $model = null): AiResponse
    {
        $command = $this->config['execute'];

        // Insert --model flag if specified (claude and gemini support this)
        if ($model !== null && in_array($this->name, ['claude', 'gemini'], true)) {
            // Insert after the binary name (position 1 for claude, position 1 for gemini)
            array_splice($command, 1, 0, ['--model', $model]);
        }

        // For tools that don't support stdin, append prompt as argument
        if (! $this->config['stdin']) {
            $command[] = $prompt;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->executeEnvPolicy()->apply();

        $process = proc_open($command, $descriptors, $pipes, $workingDir, $env);

        if (! is_resource($process)) {
            return new AiResponse(false, '', 'Failed to start AI process', 1);
        }

        try {
            // Write prompt to stdin if tool supports it
            if ($this->config['stdin']) {
                fwrite($pipes[0], $prompt);
            }
            fclose($pipes[0]);

            // Set non-blocking for timeout handling
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $output = '';
            $error = '';
            $startTime = time();

            while (true) {
                $status = proc_get_status($process);

                // Read available output
                $chunk = stream_get_contents($pipes[1]);
                if ($chunk !== false) {
                    $output .= $chunk;
                }

                $errChunk = stream_get_contents($pipes[2]);
                if ($errChunk !== false) {
                    $error .= $errChunk;
                }

                if (! $status['running']) {
                    break;
                }

                if ((time() - $startTime) > $timeout) {
                    proc_terminate($process);

                    return new AiResponse(false, $output, 'Timeout after '.$timeout.'s', 124);
                }

                usleep(100_000); // 100ms
            }

            // Final read
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) {
                $output .= $chunk;
            }

            $errChunk = stream_get_contents($pipes[2]);
            if ($errChunk !== false) {
                $error .= $errChunk;
            }
        } finally {
            // Always close pipes and process
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
            $exitCode = proc_close($process);
        }

        return new AiResponse(
            success: $exitCode === 0,
            output: trim($output),
            error: trim($error),
            exitCode: $exitCode,
        );
    }

    private static function checkAvailable(string $command): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, self::detectionEnvPolicy()->apply());

        if (! is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $startTime = time();
        $timeout = 5; // seconds — version check should be instant

        while (true) {
            $status = proc_get_status($process);

            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) {
                $output .= $chunk;
            }

            if (! $status['running']) {
                break;
            }

            if ((time() - $startTime) > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return null;
            }

            usleep(100_000);
        }

        // Final read
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false) {
            $output .= $chunk;
        }

        $exitCode = $status['exitcode'] ?? -1;
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($exitCode === 0 && $output !== '') {
            return trim($output);
        }

        return null;
    }

    /**
     * Environment policy for an actual prompt execution.
     *
     * Per-provider isolation: the child sees ONLY this tool's own provider
     * credentials (claude → ANTHROPIC_*, codex → OPENAI_*, gemini → GOOGLE/GEMINI),
     * plus base infrastructure (PATH, locale, proxy, CA, node locators). Cross-
     * provider keys and unrelated secrets (GITHUB_TOKEN, CI tokens) never reach
     * the child. AI-nesting markers are stripped by EnvPolicy::apply().
     *
     * An unknown tool name resolves to no provider credentials (fail-closed):
     * the AI call will run credential-less rather than leak another provider's
     * secrets. The only names in play are the three built-ins from tools().
     *
     * @internal Exposed for selection tests; not part of any public contract.
     */
    public function executeEnvPolicy(): EnvPolicy
    {
        return EnvPolicy::forAiTool($this->name);
    }

    /**
     * Environment policy for a `--version` detection probe.
     *
     * Detection runs before any provider is chosen and must never receive
     * credentials — minimal() passes only PATH/locale/infra and strips
     * AI-nesting markers.
     *
     * @internal Exposed for selection tests; not part of any public contract.
     */
    public static function detectionEnvPolicy(): EnvPolicy
    {
        return EnvPolicy::minimal();
    }
}
