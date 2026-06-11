<?php

declare(strict_types=1);

namespace Tessera\Installer\Commands;

use Tessera\Installer\Console;
use Tessera\Installer\Stacks\StackRegistry;

/**
 * `tessera stacks`
 *
 * Lists every registered stack so the valid `--stack=<name>` values are
 * discoverable from the CLI (previously only documented in the README or
 * the stacks/ directory). For each stack it prints the registry key (the
 * `--stack` value), the display label, a one-line description, and whether
 * the stack is ready on this system — reusing the per-stack preflight()
 * readiness check that powers the "Available stacks" block in `tessera new`,
 * including the "missing: …" reason for unavailable ones.
 */
final class StacksCommand implements CommandInterface
{
    public function description(): string
    {
        return 'List available stacks and their availability on this system.';
    }

    public function help(): void
    {
        Console::line();
        Console::bold('tessera stacks — list available stacks and their availability');
        Console::line();
        Console::line('Usage:');
        Console::line('  tessera stacks');
        Console::line();
        Console::line('Shows each stack key (the --stack value), its label, a one-line');
        Console::line('description, and whether the required tools are present on this system.');
        Console::line();
        Console::line('Options:');
        Console::line('  -h, --help   Show this help.');
        Console::line();
        Console::line('Example:');
        Console::line('  tessera new my-shop --stack=laravel');
        Console::line();
    }

    public function run(array $args): int
    {
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->help();

            return 0;
        }

        Console::line();
        Console::cyan('  TESSERA — Available stacks');
        Console::line();
        Console::line('  Pass a key to --stack to skip AI stack selection, e.g. tessera new my-app --stack=laravel');
        Console::line();

        foreach (StackRegistry::all() as $name => $stack) {
            $check = $stack->preflight();

            Console::bold("  {$name} — {$stack->label()}");
            Console::line("    {$stack->description()}");

            if ($check['ready']) {
                Console::success('ready');
            } else {
                Console::line("    \033[90mmissing: ".implode(', ', $check['missing'])."\033[0m");
            }

            Console::line();
        }

        return 0;
    }
}
