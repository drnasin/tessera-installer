<?php

declare(strict_types=1);

namespace Tessera\Installer\Commands;

use Tessera\Installer\Console;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanDiff;

/**
 * `tessera plan diff <before.json> <after.json>`
 *
 * Semantic diff of two compiled plans. Categories: added/removed steps,
 * prompt fingerprint changes, dependency edits, adapter/model hint
 * changes, complexity changes.
 *
 * Exit codes:
 *   0 — plans are semantically identical (hashes match)
 *   1 — usage error
 *   2 — semantic differences exist (CI-friendly: a non-zero diff is a
 *       signal, not necessarily an error)
 */
final class PlanDiffCommand implements CommandInterface
{
    public function description(): string
    {
        return 'Show the semantic diff between two compiled plans.';
    }

    public function help(): void
    {
        Console::line();
        Console::bold('tessera plan diff — semantic diff between two compiled plans');
        Console::line();
        Console::line('Usage:');
        Console::line('  tessera plan diff <before.json> <after.json>');
        Console::line();
        Console::line('Arguments:');
        Console::line('  <before.json>  Baseline plan.');
        Console::line('  <after.json>   Plan to compare against the baseline.');
        Console::line();
        Console::line('Options:');
        Console::line('  -h, --help     Show this help.');
        Console::line();
        Console::line('Exit codes:');
        Console::line('  0  Plans are semantically identical.');
        Console::line('  2  Semantic differences exist.');
        Console::line();
        Console::line('Example:');
        Console::line('  tessera plan diff old/plan.json new/plan.json');
        Console::line();
    }

    public function run(array $args): int
    {
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->help();

            return 0;
        }

        if (count($args) < 2) {
            Console::error('Need exactly two plan paths.');
            Console::line('Usage: tessera plan diff <before.json> <after.json>');

            return 1;
        }

        $beforePath = Console::normalizePath($args[0]);
        $afterPath = Console::normalizePath($args[1]);

        try {
            $before = (new PlanCompiler)->read($beforePath);
            $after = (new PlanCompiler)->read($afterPath);
        } catch (\Throwable $e) {
            Console::error('Could not load plans: '.$e->getMessage());

            return 1;
        }

        $diff = PlanDiff::between($before, $after);

        Console::line();
        Console::cyan('Plan diff');
        Console::line("  before: {$beforePath}  hash {$this->shortHash($diff->beforeHash)}");
        Console::line("  after:  {$afterPath}  hash {$this->shortHash($diff->afterHash)}");
        Console::line();

        if ($diff->isEmpty()) {
            Console::success('Plans are semantically identical.');

            return 0;
        }

        if ($diff->stackChanged) {
            Console::warn("Stack changed: {$diff->beforeStack} → {$diff->afterStack}");
            Console::line();
        }

        $this->printList('Added steps', $diff->addedSteps);
        $this->printList('Removed steps', $diff->removedSteps);
        $this->printList('Prompt body changed', $diff->promptChanged);
        $this->printList('Dependencies edited', $diff->dependenciesChanged);
        $this->printList('Adapter/model hints changed', $diff->hintsChanged);
        $this->printList('Complexity changed', $diff->complexityChanged);

        return 2;
    }

    private function printList(string $label, array $items): void
    {
        if ($items === []) {
            return;
        }

        Console::bold("  {$label}:");

        foreach ($items as $item) {
            Console::line("    - {$item}");
        }

        Console::line();
    }

    private function shortHash(string $hash): string
    {
        return substr($hash, 0, 12).'…';
    }
}
