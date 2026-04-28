<?php

declare(strict_types=1);

namespace Tessera\Installer\Adapters;

use Tessera\Installer\AiResponse;
use Tessera\Installer\Events\EventLog;
use Tessera\Installer\Events\EventType;

/**
 * Shared subprocess execution for AI CLI adapters.
 *
 * Concrete adapters (Claude, Codex, Gemini, future: Groq, Ollama) implement
 * just three abstract methods — detectCommand(), buildExecuteCommand(),
 * usesStdin() — and inherit:
 *
 *   - non-blocking proc_open with timeout
 *   - environment scrubbing (drops AI-nesting markers and inherited
 *     credentials of OTHER providers)
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

        $startedAt = microtime(true);
        $response = $this->runProcess($command, $stdinPayload, $context->workingDir, $context->timeout);
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
        $result = $this->runProcess($this->detectCommand(), null, null, 5);

        if (! $result->success) {
            return null;
        }

        $line = trim($result->output);

        return $line === '' ? null : $line;
    }

    /**
     * Run a subprocess via array argv (no shell), with non-blocking I/O,
     * scrubbed environment, and a hard timeout.
     *
     * @param  list<string>  $command
     */
    protected function runProcess(array $command, ?string $stdin, ?string $workingDir, int $timeout): AiResponse
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->buildChildEnv();

        $process = @proc_open($command, $descriptors, $pipes, $workingDir, $env);

        if (! is_resource($process)) {
            return new AiResponse(false, '', 'Failed to start process', 1);
        }

        $timedOut = false;
        $pid = null;

        try {
            if ($stdin !== null) {
                @fwrite($pipes[0], $stdin);
            }
            @fclose($pipes[0]);

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $output = '';
            $error = '';
            $startTime = time();

            // Timeout-check loop. We deliberately do NOT read pipes here:
            // PHP's "non-blocking" mode on proc_open pipes is broken on
            // Windows — both stream_get_contents() and fread() block
            // until every descendant releases the pipe handle. That
            // makes the time check below unreachable while the AI CLI
            // is alive (smoke run: 300s timeout fired only after 633s).
            // Reads happen exactly once below, after the process has
            // actually exited or we have killed it.
            while (true) {
                $status = proc_get_status($process);
                $pid ??= $status['pid'] ?? null;

                if (! $status['running']) {
                    break;
                }

                if ((time() - $startTime) > $timeout) {
                    $timedOut = true;

                    // proc_terminate alone leaves grandchildren (e.g. node spawned
                    // by the AI CLI) running. Their open pipe handles then make
                    // proc_close() block until they self-exit. Force-kill the
                    // whole process tree first so the cleanup below is instant.
                    if ($pid !== null) {
                        self::killProcessTree($pid);
                    }

                    @proc_terminate($process, 9);

                    break;
                }

                usleep(100_000);
            }

            // Drain whatever was buffered after the loop exit. Skip on
            // timeout: any pending output belongs to grandchildren we
            // just killed, and reading from their not-yet-released pipe
            // handles would block 5-30s on Windows for no value.
            if (! $timedOut) {
                while (! feof($pipes[1])) {
                    $chunk = @fread($pipes[1], 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $output .= $chunk;
                }
                while (! feof($pipes[2])) {
                    $errChunk = @fread($pipes[2], 8192);
                    if ($errChunk === false || $errChunk === '') {
                        break;
                    }
                    $error .= $errChunk;
                }
            }
        } finally {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                @fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                @fclose($pipes[2]);
            }
            $exitCode = @proc_close($process);
        }

        if ($timedOut) {
            return new AiResponse(
                success: false,
                output: trim($output),
                error: 'Timeout after '.$timeout.'s',
                exitCode: 124,
            );
        }

        return new AiResponse(
            success: $exitCode === 0,
            output: trim($output),
            error: trim($error),
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
     * Best-effort throughout — failures here mean the cleanup is partial,
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

    /**
     * Strip AI-nesting markers so a child Claude process does not detect a
     * parent Claude session and refuse to run. Each concrete adapter narrows
     * further if it wants to drop other providers' credentials.
     *
     * @return array<string, string>
     */
    protected function buildChildEnv(): array
    {
        $env = getenv();

        if (! is_array($env)) {
            return [];
        }

        $stripped = [
            'CLAUDECODE',
            'CLAUDE_CODE',
            'CLAUDE_CODE_SSE_PORT',
            'CLAUDE_CODE_ENTRYPOINT',
            'VIPSHOME',
        ];

        foreach ($stripped as $var) {
            unset($env[$var]);
        }

        return $env;
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
