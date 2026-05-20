<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Safe-identifier validation for database names and connection strings.
 *
 * SQL DDL (CREATE DATABASE, USE {name}) cannot be parameterised — the
 * identifier is part of the syntax, not a value. The only safe approach is a
 * strict allowlist of characters that can never break out of the identifier
 * position or be interpreted as SQL/shell syntax.
 */
final class DatabaseIdentifier
{
    /**
     * Allowed characters for a database identifier:
     *   - letters, digits, underscore, hyphen
     *   - length 1..63 (PostgreSQL's NAMEDATALEN limit; MySQL allows up to 64)
     *   - must not start with a digit or hyphen (portability with all engines)
     *
     * Rejects: whitespace, quote, backtick, semicolon, pipe, backslash,
     * dollar, parens, dots, and any Unicode outside ASCII alphanumerics.
     */
    public static function isValid(string $name): bool
    {
        if ($name === '' || strlen($name) > 63) {
            return false;
        }

        // \A ... \z anchors to start/end of subject — '$' alone allows a
        // trailing newline ("db\n" would match /^[A-Za-z]+$/), which would
        // defeat the point of the check.
        return (bool) preg_match('/\A[A-Za-z_][A-Za-z0-9_\-]*\z/', $name);
    }

    /**
     * Throw if the identifier is not safe.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(string $name, string $fieldLabel = 'identifier'): void
    {
        if (! self::isValid($name)) {
            throw new \InvalidArgumentException(
                "Invalid {$fieldLabel} '{$name}': must be 1-63 characters, start with a letter "
                .'or underscore, and contain only letters, digits, underscore, or hyphen.',
            );
        }
    }

    /**
     * Quote a safe identifier for MySQL/MariaDB DDL.
     *
     * MySQL treats `my-project` as an expression unless it is quoted. The
     * allowlist already rejects backticks, but escaping here keeps the method
     * correct if validation changes later.
     */
    public static function quoteMySql(string $name): string
    {
        self::assertValid($name, 'database name');

        return '`'.str_replace('`', '``', $name).'`';
    }
}
