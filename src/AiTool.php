<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Detects and executes AI CLI tools without any framework dependency.
 */
final class AiTool
{
    private const TOOLS = [
        'claude' => [
            'binary' => 'claude',
            'detect' => 'claude --version',
            'execute' => ['claude', '-p', '--dangerously-skip-permissions', '--output-format', 'text', '--verbose'],
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
     * Detect the best available AI tool.
     */
    public static function detect(): ?self
    {
        foreach (self::TOOLS as $name => $config) {
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

        foreach (self::TOOLS as $name => $config) {
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

        foreach (self::TOOLS as $name => $config) {
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

        $env = self::cleanEnv();

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

                    return new AiResponse(false, $output, 'Timeout after ' . $timeout . 's', 124);
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
        $output = [];
        $exitCode = 0;

        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode === 0 && ! empty($output)) {
            return trim(implode(' ', $output));
        }

        return null;
    }

    /**
     * Build clean environment without AI nesting markers.
     */
    private static function cleanEnv(): array
    {
        $env = getenv();

        if (! is_array($env)) {
            return [];
        }

        $remove = [
            'CLAUDECODE',
            'CLAUDE_CODE',
            'CLAUDE_CODE_SSE_PORT',
            'CLAUDE_CODE_ENTRYPOINT',
            'VIPSHOME',
        ];

        foreach ($remove as $var) {
            unset($env[$var]);
        }

        return $env;
    }
}
