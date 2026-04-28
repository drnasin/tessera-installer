<?php

declare(strict_types=1);

namespace Tessera\Installer\Commands;

/**
 * Minimal contract for sub-commands invoked from `bin/tessera`.
 *
 * Sprint 1 keeps the dispatcher in `bin/tessera` itself — a small switch
 * that builds the right command from the argv tail. Sprint 2 will move
 * to a tiny Application that auto-discovers commands and prints `--help`.
 *
 * Commands return a process exit code (0 = success, non-zero = error).
 */
interface CommandInterface
{
    /**
     * Short one-line description shown in `tessera --help`.
     */
    public function description(): string;

    /**
     * Execute with the remaining argv (after the command name).
     *
     * @param  list<string>  $args
     */
    public function run(array $args): int;
}
