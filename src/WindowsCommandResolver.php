<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Shared Windows binary resolution for array-argv subprocess spawning.
 *
 * Background: PHP's proc_open() with an array (no shell) executes the binary
 * directly via CreateProcess on Windows. CreateProcess appends only `.exe`
 * when resolving a bare binary name — it does NOT consult PATHEXT. npm global
 * installs ship `claude.cmd` / `gemini.cmd` / `codex.cmd` (and `.ps1`, bash)
 * but no `.exe`, so a bare `proc_open(['claude', ...])` simply fails to start.
 *
 * This resolver walks PATH (honouring PATHEXT) to find the real `.cmd`/`.bat`/
 * `.exe` for a bare binary, and — because batch wrappers cannot be launched by
 * CreateProcess directly — wraps `.cmd`/`.bat` with the command processor
 * (`cmd.exe /D /S /C ...`). On non-Windows platforms argv is returned unchanged.
 *
 * This logic was previously duplicated verbatim in CommandRunner and
 * AbstractAdapter; it now lives here once and both delegate to it (issue #50),
 * which also lets AiTool gain the same behaviour (issue #48).
 */
final class WindowsCommandResolver
{
    /**
     * Resolve argv for a shell-free spawn on the current OS.
     *
     * @param  array<int, string>  $argv  [binary, arg1, ...] — never a single string.
     * @param  string|null  $cwd  Working directory used to resolve relative binary paths;
     *                            falls back to getcwd() when null.
     * @return array<int, string>
     */
    public static function prepare(array $argv, ?string $cwd): array
    {
        if (PHP_OS_FAMILY !== 'Windows' || $argv === []) {
            return $argv;
        }

        $resolved = self::resolveWindowsBinary($argv[0], $cwd ?? (getcwd() ?: '.'));
        if ($resolved !== null) {
            $argv[0] = $resolved;
        }

        $extension = strtolower(pathinfo($argv[0], PATHINFO_EXTENSION));
        if (! in_array($extension, ['bat', 'cmd'], true)) {
            return $argv;
        }

        // Hand the batch wrapper to cmd.exe as separate argv tokens. PHP's
        // CreateProcess quoting keeps whitespace and metacharacters inside
        // each token, while cmd.exe provides the batch-file launcher.
        return array_merge(
            [self::windowsCommandProcessor(), '/D', '/S', '/C'],
            $argv,
        );
    }

    private static function windowsCommandProcessor(): string
    {
        $comspec = getenv('COMSPEC');

        return is_string($comspec) && $comspec !== '' ? $comspec : 'cmd.exe';
    }

    private static function resolveWindowsBinary(string $binary, string $cwd): ?string
    {
        $extensions = self::windowsExecutableExtensions();

        foreach (self::candidateBinaryPaths($binary, $cwd, $extensions) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    private static function candidateBinaryPaths(string $binary, string $cwd, array $extensions): array
    {
        if (self::looksLikePath($binary)) {
            $base = self::normalizeCandidatePath($binary, $cwd);

            return self::expandWindowsBinaryCandidates($base, $extensions);
        }

        $path = getenv('PATH');
        if (! is_string($path) || $path === '') {
            return [];
        }

        $candidates = [];

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }

            $base = rtrim($dir, '\\/').DIRECTORY_SEPARATOR.$binary;
            foreach (self::expandWindowsBinaryCandidates($base, $extensions) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private static function looksLikePath(string $binary): bool
    {
        return str_contains($binary, '\\')
            || str_contains($binary, '/')
            || preg_match('/^[A-Za-z]:/', $binary) === 1
            || str_starts_with($binary, '.');
    }

    private static function normalizeCandidatePath(string $binary, string $cwd): string
    {
        if (preg_match('/^[A-Za-z]:/', $binary) === 1 || str_starts_with($binary, '\\\\')) {
            return $binary;
        }

        return rtrim($cwd, '\\/').DIRECTORY_SEPARATOR.$binary;
    }

    /**
     * @param  array<int, string>  $extensions
     * @return array<int, string>
     */
    private static function expandWindowsBinaryCandidates(string $base, array $extensions): array
    {
        if (pathinfo($base, PATHINFO_EXTENSION) !== '') {
            return [$base];
        }

        $candidates = [];

        foreach ($extensions as $extension) {
            $candidates[] = $base.$extension;
        }

        $candidates[] = $base;

        return $candidates;
    }

    /**
     * @return array<int, string>
     */
    private static function windowsExecutableExtensions(): array
    {
        $pathext = getenv('PATHEXT');

        if (! is_string($pathext) || $pathext === '') {
            return ['.com', '.exe', '.bat', '.cmd'];
        }

        $extensions = [];

        foreach (explode(';', $pathext) as $extension) {
            $extension = strtolower(trim($extension));
            if ($extension === '') {
                continue;
            }

            $extensions[] = str_starts_with($extension, '.') ? $extension : '.'.$extension;
        }

        return $extensions === [] ? ['.com', '.exe', '.bat', '.cmd'] : $extensions;
    }
}
