<?php

declare(strict_types=1);

namespace Tessera\Installer\Commands;

use Tessera\Installer\Console;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanStep;

/**
 * `tessera plan show <plan.json>`
 *
 * Pretty-prints a compiled plan: header (stack, hash, compiled_at) and a
 * tabular per-step summary (id, complexity, adapter, prompt fingerprint,
 * deps). Useful right after `plan compile` to verify that the plan is
 * what you expect before executing it.
 */
final class PlanShowCommand implements CommandInterface
{
    public function description(): string
    {
        return 'Show the contents of a compiled plan.json.';
    }

    public function help(): void
    {
        Console::line();
        Console::bold('tessera plan show — pretty-print a compiled plan.json');
        Console::line();
        Console::line('Usage:');
        Console::line('  tessera plan show [<plan.json>]');
        Console::line();
        Console::line('Arguments:');
        Console::line('  <plan.json>  Path to the compiled plan (default: ./.tessera/plan.json).');
        Console::line();
        Console::line('Options:');
        Console::line('  -h, --help   Show this help.');
        Console::line();
        Console::line('Example:');
        Console::line('  tessera plan show build/plan.json');
        Console::line();
    }

    public function run(array $args): int
    {
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->help();

            return 0;
        }

        $path = $args[0] ?? getcwd().'/.tessera/plan.json';

        try {
            $plan = (new PlanCompiler)->read($path);
        } catch (\Throwable $e) {
            Console::error('Could not load plan: '.$e->getMessage());

            return 1;
        }

        Console::line();
        Console::cyan("Plan: {$path}");
        Console::line("  Stack:    {$plan->stack}");
        Console::line('  Steps:    '.count($plan->steps));
        Console::line('  Hash:     '.substr($plan->planHash, 0, 32).'…');
        Console::line("  Compiled: {$plan->compiledAt}  (compiler v{$plan->compilerVersion})");
        Console::line();

        Console::bold('Steps (topological order):');
        Console::line();

        foreach ($plan->inTopologicalOrder() as $idx => $step) {
            $this->printStep($idx + 1, $step);
        }

        return 0;
    }

    private function printStep(int $position, PlanStep $step): void
    {
        $adapterDisplay = $step->adapterHint ?? '(router)';
        $modelDisplay = $step->modelHint ?? '(default)';
        $deps = $step->dependencies === [] ? '-' : implode(', ', $step->dependencies);
        $fpShort = substr($step->promptFingerprint, 0, 12);

        Console::line("  [{$position}] {$step->id} — {$step->name}");
        Console::line("        complexity: {$step->complexity->value}");
        Console::line("        adapter:    {$adapterDisplay}    model: {$modelDisplay}");
        Console::line("        deps:       {$deps}");
        Console::line("        fingerprint: {$fpShort}…");

        if ($step->gates !== []) {
            $gateTypes = array_map(fn (array $g): string => (string) ($g['type'] ?? 'unknown'), $step->gates);
            Console::line('        gates:      '.implode(', ', $gateTypes));
        }

        Console::line();
    }
}
