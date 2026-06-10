<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Read/write `.env` files with correct value quoting.
 *
 * Laravel's DotEnv parser treats the following as delimiters or special chars:
 *   whitespace, `#` (comment), `$` (interpolation when unquoted), `"` (string),
 *   backslash (escape), newline (end of value).
 *
 * Unquoted values that contain any of those are ambiguous or invalid. The
 * safe rule: when a value contains anything outside [A-Za-z0-9_./:=-],
 * wrap it in double quotes and escape `"`, `\`, `$`, newline.
 *
 * Pure-ASCII values with no special chars are emitted bare (matches existing
 * Laravel `.env` convention).
 */
final class EnvFile
{
    /**
     * Format a single value for `.env` line. Does NOT include the `KEY=` prefix.
     */
    public static function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Safe characters that need no quoting at all.
        if (preg_match('/^[A-Za-z0-9_.\/:=\-]+$/', $value) === 1) {
            return $value;
        }

        // Anything else gets double-quoted with the four dangerous characters escaped.
        $escaped = str_replace(
            ['\\',  '"',   '$',    "\n",  "\r"],
            ['\\\\', '\\"', '\\$', '\\n', '\\r'],
            $value,
        );

        return '"'.$escaped.'"';
    }

    /**
     * Return `$env` with `KEY=` (optionally preceded by whitespace) replaced or appended.
     *
     * The value is quoted via self::quote(). Existing lines are matched by
     * the key only (any existing value is overwritten). If the key is not
     * present, a new line is appended.
     */
    public static function setKey(string $env, string $key, string $value): string
    {
        self::assertValidKey($key);

        $line = $key.'='.self::quote($value);

        // Replace existing assignment, preserving trailing newline if any.
        // Escape both backslash AND dollar in the replacement string: preg_replace
        // treats `$N`/`\N` as backreferences, so a value containing `$1` would
        // otherwise be swallowed (the pattern has no capture groups → empty string).
        $pattern = '/^'.preg_quote($key, '/').'=.*/m';

        if (preg_match($pattern, $env) === 1) {
            $replacement = str_replace(['\\', '$'], ['\\\\', '\\$'], $line);
            $replaced = preg_replace($pattern, $replacement, $env, 1);

            return is_string($replaced) ? $replaced : $env;
        }

        // Append, ensuring the file ends with a newline before we add.
        if ($env !== '' && ! str_ends_with($env, "\n")) {
            $env .= "\n";
        }

        return $env.$line."\n";
    }

    /**
     * Remove `KEY=...` line entirely.
     */
    public static function removeKey(string $env, string $key): string
    {
        self::assertValidKey($key);

        $pattern = '/^'.preg_quote($key, '/').'=.*\n?/m';
        $result = preg_replace($pattern, '', $env);

        return is_string($result) ? $result : $env;
    }

    private static function assertValidKey(string $key): void
    {
        if (! preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException("Invalid .env key '{$key}': must be uppercase letters, digits, and underscores.");
        }
    }
}
