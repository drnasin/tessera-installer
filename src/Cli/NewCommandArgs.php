<?php

declare(strict_types=1);

namespace Tessera\Installer\Cli;

/**
 * Parses argv for `tessera new <directory> [flags]`.
 *
 * Extracted from bin/tessera so the parsing logic is unit-testable
 * without spinning up the whole NewCommand pipeline. The shape is
 * deliberately tiny — three flags today (`--force`, `--stack`,
 * `--requirements-fixture`), and a directory name. New flags get
 * a dedicated method here, not another inline foreach in the bin
 * script.
 *
 * Both `--flag value` and `--flag=value` forms are accepted for the
 * value-bearing flags. Boolean flags accept the short form too
 * (`-f` for `--force`).
 */
final readonly class NewCommandArgs
{
    public function __construct(
        public ?string $directory,
        public bool $force,
        public ?string $forcedStack,
        public ?string $requirementsFixturePath,
    ) {}

    /**
     * Parse the argv tail after the `new` command name.
     *
     * @param  list<string>  $args  argv slice — everything after "new"
     */
    public static function parse(array $args): self
    {
        $directory = self::firstPositional($args);
        $force = in_array('--force', $args, true) || in_array('-f', $args, true);

        return new self(
            directory: $directory,
            force: $force,
            forcedStack: self::extractValueFlag($args, '--stack'),
            requirementsFixturePath: self::extractValueFlag($args, '--requirements-fixture'),
        );
    }

    /**
     * The first argv element that is not a flag or a flag value.
     * Recognises that `--stack name` consumes two slots and `name`
     * after it is the flag's value, not the directory.
     *
     * @param  list<string>  $args
     */
    private static function firstPositional(array $args): ?string
    {
        $skip = false;
        foreach ($args as $arg) {
            if ($skip) {
                $skip = false;

                continue;
            }

            if ($arg === '--stack' || $arg === '--requirements-fixture') {
                // Next slot is this flag's value.
                $skip = true;

                continue;
            }

            if (str_starts_with($arg, '-')) {
                continue;
            }

            return $arg;
        }

        return null;
    }

    /**
     * @param  list<string>  $args
     */
    private static function extractValueFlag(array $args, string $flag): ?string
    {
        $equalsForm = $flag.'=';

        foreach ($args as $idx => $arg) {
            if (str_starts_with($arg, $equalsForm)) {
                return substr($arg, strlen($equalsForm));
            }

            if ($arg === $flag && isset($args[$idx + 1])) {
                return $args[$idx + 1];
            }
        }

        return null;
    }
}
