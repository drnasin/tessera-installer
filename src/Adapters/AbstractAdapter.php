<?php

declare(strict_types=1);

namespace Tessera\Installer\Adapters;

use Tessera\Installer\AiResponse;
use Tessera\Installer\EnvPolicy;
use Tessera\Installer\Events\EventLog;
use Tessera\Installer\Events\EventType;
use Tessera\Installer\WindowsCommandResolver;

/**
 * Shared subprocess execution for AI CLI adapters.
 *
 * Concrete adapters (Claude, Codex, Gemini, future: Groq, Ollama) implement
 * just three abstract methods - detectCommand(), buildExecuteCommand(),
 * usesStdin() - and inherit:
 *
 *   - subprocess execution with timeout
 *   - per-provider environment isolation via EnvPolicy: an execute call sees
 *     ONLY its own provider's credentials (forAiTool), a detection probe sees
 *     none (minimal); AI-nesting markers are always stripped
 *   - cached availability/version probing
 *   - uniform event emission to the optional EventLog
 *
 * The class is intentionally not final: NodeAdapter or HttpAdapter could
 * extend it to share the proc_open logic without re-implementing it.
 */
abstract class AbstractAdapter implements AdapterInterface
{
    private ?string $cachedVersion = null;

    private ?bool $cachedAvailability = null;

    abstract public function name(): string;

    /**
     * Argv command that prints version and exits non-zero when not installed.
     *
     * @return list<string>
     */
    abstract protected function detectCommand(): array;

    /**
     * Argv command for the actual prompt execution.
     *
     * @return list<string>
     */
    abstract protected function buildExecuteCommand(string $prompt, AdapterContext $context): array;

    /**
     * True if the prompt is fed via stdin; false if it goes as last argv arg.
     */
    abstract protected function usesStdin(): bool;

    public function isAvailable(): bool
    {
        if ($this->cachedAvailability !== null) {
            return $this->cachedAvailability;
        }

        $this->cachedVersion = $this->probeVersion();
        $this->cachedAvailability = $this->cachedVersion !== null;

        return $this->cachedAvailability;
    }

    public function version(): ?string
    {
        if ($this->cachedAvailability === null) {
            $this->isAvailable();
        }

        return $this->cachedVersion;
    }

    public function supportsModel(?string $model): bool
    {
        return true;
    }

    public function estimateCost(int $estimatedInputTokens, ?int $estimatedOutputTokens = null): ?float
    {
        return null;
    }

    public function execute(string $prompt, AdapterContext $context): AiResponse
    {
        $command = $this->buildExecuteCommand($prompt, $context);
        $stdinPayload = $this->usesStdin() ? $prompt : null;

        $context->eventLog?->emit(EventType::AiCallStart, [
            'adapter' => $this->name(),
            'model' => $context->model,
            'timeout' => $context->timeout,
            'step' => $context->stepName,
        ], $context->traceId);

        // Per-provider isolation: this child sees ONLY its own provider's
        // credentials. Cross-provider keys and unrelated secrets (GITHUB_TOKEN,
        // CI tokens) are filtered out by the EnvPolicy allowlist.
        $env = EnvPolicy::forAiTool($this->name())->apply();

        $startedAt = microtime(true);
        $response = $this->runProcess($command, $stdinPayload, $context->workingDir, $context->timeout, $env);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->emitCompletion($context->eventLog, $context, $response, $durationMs);

        return $response;
    }

    /**
     * Probe the underlying CLI for its version. Returns null when not found
     * or when the probe fails within 5 seconds.
     */
    protected function probeVersion(): ?string
    {
        // A `--version` probe runs before any provider is selected and must
        // receive NO credentials — minimal() passes only PATH/locale/infra.
        $env = EnvPolicy::minimal()->apply();

        $result = $this->runProcess($this->detectCommand(), null, null, 5, $env);

        if (! $result->success) {
            return null;
        }

        $line = trim($result->output);

        return $line === '' ? null : $line;
    }

    /**
     * Run a subprocess via array argv (no shell), with scrubbed environment
     * and a hard timeout.
     *
     * Stdout/stderr go to temp files instead of pipes. That avoids Windows
     * pipe deadlocks and allows verbose AI runs to emit large output without
     * blocking the child process.
     *
     * The caller MUST supply the already-filtered child environment (via
     * EnvPolicy). There is no implicit env-building fallback here — that keeps
     * credential isolation a structural property: an execute call passes
     * forAiTool(), a detection probe passes minimal(), and neither can silently
     * inherit the other's environment.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env  Filtered child environment.
     */
    protected function runProcess(array $command, ?string $stdin, ?string $workingDir, int $timeout, array $env): AiResponse
    {
        $cwd = $workingDir ?? (getcwd() ?: null);
        $preparedCommand = self::prepareCommand($command, $cwd);
        $stdoutFile = self::tempPath('adapter_stdout');
        $stderrFile = self::tempPath('adapter_stderr');
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $process = @proc_open($preparedCommand, $descriptors, $pipes, $cwd, $env);

        if (! is_resource($process)) {
            @unlink($stdoutFile);
            @unlink($stderrFile);

            return new AiResponse(false, '', 'Failed to start process', 1);
        }

        $timedOut = false;
        $pid = null;
        $exitCode = -1;

        try {
            if ($stdin !== null && isset($pipes[0]) && is_resource($pipes[0])) {
                @fwrite($pipes[0], $stdin);
            }

            if (isset($pipes[0]) && is_resource($pipes[0])) {
                @fclose($pipes[0]);
            }

            $startedAt = microtime(true);

            while (true) {
                $status = proc_get_status($process);
                $pid ??= $status['pid'] ?? null;

                if (! $status['running']) {
                    $exitCode = is_int($status['exitcode']) ? $status['exitcode'] : -1;

                    break;
                }

                if ((microtime(true) - $startedAt) > $timeout) {
                    $timedOut = true;

                    if ($pid !== null) {
                        self::killProcessTree($pid);
                    }

                    @proc_terminate($process, 9);

                    break;
                }

                usleep(100_000);
            }
        } finally {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                @fclose($pipes[0]);
            }

            @proc_close($process);
        }

        $output = @file_get_contents($stdoutFile);
        $error = @file_get_contents($stderrFile);

        @unlink($stdoutFile);
        @unlink($stderrFile);

        if ($timedOut) {
            return new AiResponse(
                success: false,
                output: trim(is_string($output) ? $output : ''),
                error: 'Timeout after '.$timeout.'s',
                exitCode: 124,
            );
        }

        return new AiResponse(
            success: $exitCode === 0,
            output: trim(is_string($output) ? $output : ''),
            error: trim(is_string($error) ? $error : ''),
            exitCode: $exitCode,
        );
    }

    /**
     * Force-terminate a process and every descendant.
     *
     * Plain proc_terminate() only signals the immediate child. That is fine
     * for shell scripts but disastrous for the AI CLIs Tessera invokes:
     * `claude` is a Node.js wrapper that spawns a long-running renderer
     * subprocess; if we kill the wrapper without its renderer the
     * grandchild keeps the pipe handles open, and PHP's proc_close()
     * blocks waiting for those handles to close. Past smoke runs hung
     * for 10+ minutes after a nominally-300-second timeout for exactly
     * this reason.
     *
     *   Windows: `taskkill /F /T /PID` walks the tree and force-kills.
     *   Unix:    walk the tree via `pgrep -P`, signal each from leaf upward.
     *
     * Best-effort throughout - failures here mean the cleanup is partial,
     * not that the caller should crash. The caller has already decided
     * the process is going away.
     */
    protected static function killProcessTree(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('taskkill /F /T /PID %d 2>NUL', $pid);
            @exec($cmd);

            return;
        }

        self::killUnixTree($pid);
    }

    private static function killUnixTree(int $pid): void
    {
        $children = [];
        $exit = 1;
        @exec(sprintf('pgrep -P %d 2>/dev/null', $pid), $children, $exit);

        if ($exit === 0) {
            foreach ($children as $child) {
                $childPid = (int) trim((string) $child);
                if ($childPid > 0 && $childPid !== $pid) {
                    self::killUnixTree($childPid);
                }
            }
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);

            return;
        }

        @exec(sprintf('kill -TERM %d 2>/dev/null', $pid));
    }

    private static function tempPath(string $suffix): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_'.$suffix.'_'.getmypid().'_'.bin2hex(random_bytes(4));
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private static function prepareCommand(array $argv, ?string $cwd): array
    {
        /** @var list<string> $prepared */
        $prepared = WindowsCommandResolver::prepare($argv, $cwd);

        return $prepared;
    }

    private function emitCompletion(?EventLog $log, AdapterContext $context, AiResponse $response, int $durationMs): void
    {
        if ($log === null) {
            return;
        }

        $eventType = match (true) {
            $response->success => EventType::AiCallComplete,
            $response->isRateLimited() => EventType::AiCallRateLimited,
            $response->isToolDown() => EventType::AiCallToolDown,
            default => EventType::AiCallComplete,
        };

        $log->emit($eventType, [
            'adapter' => $this->name(),
            'model' => $context->model,
            'success' => $response->success,
            'exit_code' => $response->exitCode,
            'duration_ms' => $durationMs,
            'output_size' => strlen($response->output),
            'error_excerpt' => $response->error !== '' ? mb_substr($response->error, 0, 500) : null,
            'step' => $context->stepName,
        ], $context->traceId);
    }
}
