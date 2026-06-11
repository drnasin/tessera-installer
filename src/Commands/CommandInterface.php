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
     * Print the per-command usage block (flags, arguments, one example).
     *
     * Invoked when the command is run with `--help` or `-h`. Commands detect
     * the flag at the top of run() and delegate here before doing any work.
     */
    public function help(): void;

    /**
     * Execute with the remaining argv (after the command name).
     *
     * @param  list<string>  $args
     */
    public function run(array $args): int;
}
