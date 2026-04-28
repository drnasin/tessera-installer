<?php

declare(strict_types=1);

namespace Tessera\Installer\Commands;

use Tessera\Installer\Console;
use Tessera\Installer\Manifest\ManifestCompiler;
use Tessera\Installer\Manifest\StackManifestLoader;

/**
 * `tessera plan compile <manifest.yaml> [-o <output.json>]`
 *
 * Reads a stack manifest YAML, compiles it to a versioned, hash-anchored
 * plan.json. By default the output goes to `.tessera/plan.json` next to
 * the working directory. Pass `-o` for a different path.
 *
 * The compile is pure — no AI is invoked. Use this to inspect what
 * `tessera new --execute` will dispatch, or to feed `tessera plan diff`.
 */
final class PlanCompileCommand implements CommandInterface
{
    public function description(): string
    {
        return 'Compile a stack YAML manifest to a versioned plan.json.';
    }

    public function run(array $args): int
    {
        $manifestPath = $args[0] ?? null;

        if ($manifestPath === null) {
            Console::error('Missing manifest path.');
            Console::line('Usage: tessera plan compile <manifest.yaml> [-o <output.json>]');

            return 1;
        }

        $outputPath = $this->parseOutputFlag($args) ?? getcwd().'/.tessera/plan.json';

        try {
            $manifest = (new StackManifestLoader)->loadFromFile($manifestPath);
            $plan = (new ManifestCompiler)->compile($manifest);

            (new \Tessera\Installer\Plan\PlanCompiler)->write($plan, $outputPath);
        } catch (\Throwable $e) {
            Console::error('Compile failed: '.$e->getMessage());

            return 1;
        }

        Console::success("Compiled plan: {$outputPath}");
        Console::line("  stack:      {$plan->stack}");
        Console::line('  steps:      '.count($plan->steps));
        Console::line('  plan hash:  '.substr($plan->planHash, 0, 16).'…');
        Console::line('  compiled:   '.$plan->compiledAt);

        return 0;
    }

    /**
     * @param  list<string>  $args
     */
    private function parseOutputFlag(array $args): ?string
    {
        foreach ($args as $idx => $value) {
            if (($value === '-o' || $value === '--output') && isset($args[$idx + 1])) {
                return $args[$idx + 1];
            }

            if (str_starts_with($value, '--output=')) {
                return substr($value, strlen('--output='));
            }
        }

        return null;
    }
}
