<?php

declare(strict_types=1);

namespace Tessera\Installer\Commands;

use Tessera\Installer\Console;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanStep;
use Tessera\Installer\ToolPreference;
use Tessera\Installer\ToolRouter;

/**
 * `tessera plan show <plan.json>`
 *
 * Pretty-prints a compiled plan: header (stack, hash, compiled_at) and a
 * tabular per-step summary (id, complexity, adapter, prompt fingerprint,
 * deps). Useful right after `plan compile` to verify that the plan is
 * what you expect before executing it.
 *
 * Adapter/model display (issue #26): the two most cost-relevant facts per
 * step are which AI tool and which model will run it. Rather than always
 * printing opaque `(router)`/`(default)` placeholders, the command resolves
 * each step's complexity through the SAME routing used at execution time
 * (`ToolRouter` + `ToolPreference::fromEnv()`) and shows the concrete pair —
 * but only when AI tools are actually detectable on this machine. When no
 * tools are found (e.g. CI), it falls back to the placeholders so the output
 * is honest that resolution could not happen. Manifest-pinned
 * `adapter_hint`/`model_hint` values always display verbatim.
 */
final class PlanShowCommand implements CommandInterface
{
    /**
     * @param  ToolRouter|null  $router  Routing source for live adapter/model
     *                                   resolution. Null means "auto-detect at
     *                                   runtime" (`ToolRouter::detect`); tests
     *                                   inject an explicit router so BOTH the
     *                                   resolved path and the no-tools path are
     *                                   deterministic regardless of what is
     *                                   installed on the machine.
     */
    public function __construct(private ?ToolRouter $router = null) {}

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

        $path = Console::normalizePath(
            $args[0] ?? getcwd().DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'plan.json',
        );

        try {
            $plan = (new PlanCompiler)->read($path);
        } catch (\Throwable $e) {
            Console::error('Could not load plan: '.$e->getMessage());
            Console::line('Hint: Run: tessera plan compile <stack.yaml> to generate .tessera/plan.json');

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

        // Resolve once: auto-detect when no router was injected. detect()
        // returns null when no AI CLI is installed (e.g. CI), which keeps the
        // placeholders for every step.
        $router = $this->router ?? ToolRouter::detect(ToolPreference::fromEnv());

        foreach ($plan->inTopologicalOrder() as $idx => $step) {
            $this->printStep($idx + 1, $step, $router);
        }

        return 0;
    }

    private function printStep(int $position, PlanStep $step, ?ToolRouter $router): void
    {
        [$adapterDisplay, $modelDisplay] = $this->resolveDisplay($step, $router);
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

    /**
     * Decide the adapter/model strings for a step.
     *
     * Precedence:
     *   1. Manifest-pinned `adapter_hint` → display verbatim (model from
     *      `model_hint`, else `(default)`). No live resolution, no suffix.
     *   2. No router, or router resolves nothing for this complexity →
     *      `(router)`/`(default)` placeholders, identical to pre-#26 output.
     *   3. Router resolves a concrete tool+model → show it, with a suffix
     *      noting the resolution is a now-snapshot that may differ at run time
     *      if tool availability changes.
     *
     * @return array{0: string, 1: string} [adapterDisplay, modelDisplay]
     */
    private function resolveDisplay(PlanStep $step, ?ToolRouter $router): array
    {
        if ($step->adapterHint !== null) {
            return [$step->adapterHint, $step->modelHint ?? '(default)'];
        }

        $selection = $router?->resolve($step->complexity);

        if ($selection === null) {
            return ['(router)', '(default)'];
        }

        $model = $selection->model ?? '(default)';

        return [
            $selection->tool->name(),
            $model.' (resolved now; may differ at run time if availability changes)',
        ];
    }
}
