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

    public function run(array $args): int
    {
        if (count($args) < 2) {
            Console::error('Need exactly two plan paths.');
            Console::line('Usage: tessera plan diff <before.json> <after.json>');

            return 1;
        }

        try {
            $before = (new PlanCompiler)->read($args[0]);
            $after = (new PlanCompiler)->read($args[1]);
        } catch (\Throwable $e) {
            Console::error('Could not load plans: '.$e->getMessage());

            return 1;
        }

        $diff = PlanDiff::between($before, $after);

        Console::line();
        Console::cyan('Plan diff');
        Console::line("  before: {$args[0]}  hash {$this->shortHash($diff->beforeHash)}");
        Console::line("  after:  {$args[1]}  hash {$this->shortHash($diff->afterHash)}");
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
