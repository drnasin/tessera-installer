<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Array-argv subprocess runner — the only safe way to spawn programs when any
 * argument contains user- or AI-provided data.
 *
 * Background: PHP's proc_open() accepts either a string (which is passed to
 * the OS shell for interpretation) or an array (which bypasses the shell and
 * executes the binary directly). The legacy Console::exec() / execSilent()
 * methods used strings, which meant anything interpolated into the command
 * (DB credentials, paths, package names) had to be perfectly shell-escaped.
 * In practice they weren't, which is shell injection.
 *
 * CommandRunner always uses array argv. Pair with EnvPolicy for credential
 * hygiene.
 *
 *   $runner = new CommandRunner();
 *   $result = $runner->run(
 *       argv: ['mysql', '-u', $user, '-h', $host, '-e', 'SELECT 1;'],
 *       cwd: $projectDir,
 *       env: EnvPolicy::buildTool(),
 *       stdin: $password,          // passed via stdin, never argv
 *       timeout: 30,
 *   );
 *
 * `$user`, `$host`, etc. are passed literally — there is no shell to
 * interpret `;` or `$(...)`, and the password never touches argv.
 *
 * Stdout/stderr are captured via temp files rather than pipes. This is more
 * portable than non-blocking pipe reads (PHP's stream_set_blocking is
 * unreliable on Windows pipes from proc_open), tolerates unlimited output
 * without risk of pipe-buffer deadlocks, and keeps the implementation simple.
 * The trade-off is no streaming — we read stdout/stderr only after the
 * process exits. That is acceptable for every installer call site.
 */
final class CommandRunner
{
    public function __construct(
        private readonly int $defaultTimeout = 300,
    ) {}

    /**
     * Run a subprocess and return its result.
     *
     * @param array<int, string> $argv     [binary, arg1, arg2, ...] — NEVER a single string.
     * @param string             $cwd      Working directory (must exist).
     * @param EnvPolicy|null     $env      Env filter; null = inherit full env (unsafe, avoid).
     * @param string|null        $stdin    Bytes to write to stdin; null = close stdin immediately.
     * @param int|null           $timeout  Seconds; null = $defaultTimeout.
     */
    public function run(
        array $argv,
        string $cwd,
        ?EnvPolicy $env = null,
        ?string $stdin = null,
        ?int $timeout = null,
    ): CommandResult {
        if (count($argv) === 0) {
            throw new \InvalidArgumentException('CommandRunner::run requires at least a binary name in argv.');
        }

        foreach ($argv as $i => $part) {
            if (! is_string($part)) {
                throw new \InvalidArgumentException("argv[{$i}] must be a string.");
            }
        }

        $timeoutSeconds = $timeout ?? $this->defaultTimeout;
        $envArray = $env !== null ? $env->apply() : null;

        $stdoutFile = self::tempPath('stdout');
        $stderrFile = self::tempPath('stderr');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        // Suppress the "The system cannot find the file specified" / "No such file"
        // warning for missing binaries — we report it via the returned 127 exit code.
        $process = @proc_open($argv, $descriptors, $pipes, $cwd, $envArray);

        if (! is_resource($process)) {
            @unlink($stdoutFile);
            @unlink($stderrFile);

            return new CommandResult(
                exitCode: 127,
                stdout: '',
                stderr: 'Failed to start: '.$argv[0],
                timedOut: false,
                durationSeconds: 0.0,
            );
        }

        $startTime = microtime(true);
        $timedOut = false;
        $exitCode = -1;
        $closed = false;

        try {
            if ($stdin !== null && $stdin !== '' && isset($pipes[0]) && is_resource($pipes[0])) {
                fwrite($pipes[0], $stdin);
            }
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            while (true) {
                $status = proc_get_status($process);

                if (! $status['running']) {
                    // proc_get_status reports the real exit code the first time
                    // it sees the process as stopped. Trust it over proc_close,
                    // which can return -1 after the child has been reaped.
                    $exitCode = is_int($status['exitcode']) ? $status['exitcode'] : -1;
                    break;
                }

                if ((microtime(true) - $startTime) > $timeoutSeconds) {
                    proc_terminate($process, 9);
                    usleep(200_000);
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        proc_terminate($process, 9);
                        usleep(200_000);
                    }
                    $timedOut = true;
                    break;
                }

                usleep(50_000);
            }
        } finally {
            if (is_resource($process) && ! $closed) {
                proc_close($process);
            }
        }

        $stdout = @file_get_contents($stdoutFile);
        $stderr = @file_get_contents($stderrFile);

        @unlink($stdoutFile);
        @unlink($stderrFile);

        return new CommandResult(
            exitCode: $timedOut ? 124 : $exitCode,
            stdout: is_string($stdout) ? $stdout : '',
            stderr: is_string($stderr) ? $stderr : '',
            timedOut: $timedOut,
            durationSeconds: microtime(true) - $startTime,
        );
    }

    private static function tempPath(string $suffix): string
    {
        $dir = sys_get_temp_dir();
        $path = $dir.DIRECTORY_SEPARATOR.'tessera_'.$suffix.'_'.getmypid().'_'.bin2hex(random_bytes(4));

        return $path;
    }
}
