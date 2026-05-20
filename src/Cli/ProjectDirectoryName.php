<?php

declare(strict_types=1);

namespace Tessera\Installer\Cli;

/**
 * Validates the single path segment accepted by `tessera new <directory>`.
 *
 * This is intentionally stricter than "can the OS create this path?". The
 * installer still has legacy shell-string call sites, and `--force` can delete
 * an existing target. Keeping the target to a conservative, portable basename
 * avoids both option-confusion and Windows path-normalisation traps.
 */
final class ProjectDirectoryName
{
    private const MAX_LENGTH = 100;

    private const RESERVED_NAMES = [
        'con',
        'prn',
        'aux',
        'nul',
        'com0',
        'com1',
        'com2',
        'com3',
        'com4',
        'com5',
        'com6',
        'com7',
        'com8',
        'com9',
        'lpt0',
        'lpt1',
        'lpt2',
        'lpt3',
        'lpt4',
        'lpt5',
        'lpt6',
        'lpt7',
        'lpt8',
        'lpt9',
    ];

    /**
     * Return null when valid, otherwise a user-facing reason.
     */
    public static function validate(string $name): ?string
    {
        if ($name === '') {
            return 'Directory name cannot be empty.';
        }

        if (strlen($name) > self::MAX_LENGTH) {
            return 'Directory name must be 100 characters or fewer.';
        }

        if (! preg_match('/\A[A-Za-z0-9._-]+\z/', $name)) {
            return 'Use only letters, numbers, -, _, and .';
        }

        if ($name === '.' || $name === '..') {
            return "'{$name}' cannot be used as a project directory.";
        }

        if (str_starts_with($name, '.')) {
            return 'Directory name cannot start with a dot.';
        }

        if (str_ends_with($name, '.')) {
            return 'Directory name cannot end with a dot.';
        }

        if (str_starts_with($name, '-')) {
            return 'Directory name cannot start with a dash.';
        }

        $baseName = strtolower(explode('.', $name, 2)[0]);
        if (in_array($baseName, self::RESERVED_NAMES, true)) {
            return "'{$name}' is reserved by Windows and cannot be used as a project directory.";
        }

        return null;
    }
}
