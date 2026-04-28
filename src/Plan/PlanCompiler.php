<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

use Tessera\Installer\Schema\ArtifactValidator;
use Tessera\Installer\Schema\SchemaVersion;

/**
 * Builds and persists CompiledPlan artifacts.
 *
 * In Sprint 1 the compiler accepts an in-memory step definition list (id,
 * name, complexity, prompt, optional hints, dependencies, gates) and
 * produces a CompiledPlan with deterministic plan_hash. Sprint 2 will add
 * a YAML/Manifest source that this same compiler consumes — the in-memory
 * shape stays the canonical input.
 *
 * Reading is round-trippable: read(write(plan)) returns a CompiledPlan
 * that compares structurally identical (same hash, same step order).
 *
 * The compiler never executes anything. PlanExecutor handles dispatch.
 */
final class PlanCompiler
{
    private ArtifactValidator $validator;

    private string $compilerVersion;

    public function __construct(?ArtifactValidator $validator = null, string $compilerVersion = '1.0')
    {
        $this->validator = $validator ?? new ArtifactValidator;
        $this->compilerVersion = $compilerVersion;
    }

    /**
     * Compile a step definition list into a hash-anchored plan.
     *
     * @param  list<PlanStep>  $steps
     * @param  array<string, mixed>  $requirements
     */
    public function compile(string $stack, array $steps, array $requirements = []): CompiledPlan
    {
        $this->validateNoDuplicateIds($steps);
        $this->validateDependenciesResolve($steps);

        $plan = new CompiledPlan(
            stack: $stack,
            steps: $steps,
            requirements: $requirements,
            planHash: CompiledPlan::computeHash($steps),
            compiledAt: gmdate('Y-m-d\TH:i:s\Z'),
            compilerVersion: $this->compilerVersion,
        );

        // Eager validation: a freshly compiled plan must topologically sort.
        $plan->inTopologicalOrder();

        return $plan;
    }

    /**
     * Write the plan to disk as pretty-printed JSON. Atomic via tmp+rename
     * so crashes don't leave a half-written file.
     */
    public function write(CompiledPlan $plan, string $path): void
    {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode(
            $plan->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if ($json === false) {
            throw new \RuntimeException('Failed to encode plan to JSON.');
        }

        $tmp = $path.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException("Failed to write tmp plan file: {$tmp}");
        }

        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
            @unlink($path);
        }

        if (! @rename($tmp, $path)) {
            if (@copy($tmp, $path)) {
                @unlink($tmp);
            } else {
                @unlink($tmp);
                throw new \RuntimeException("Failed to install plan file at: {$path}");
            }
        }
    }

    /**
     * Read a plan from disk and validate its schema and hash. Throws on
     * mismatch — by design, hand-editing plan.json should fail loudly.
     */
    public function read(string $path): CompiledPlan
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Plan file not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read plan file: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("Plan file is not valid JSON: {$path}");
        }

        $errors = $this->validator->validate(SchemaVersion::PLAN, $decoded);
        if ($errors !== []) {
            throw new \RuntimeException("Invalid plan: ".implode(' | ', $errors));
        }

        $plan = CompiledPlan::fromArray($decoded);

        if (! $plan->isHashValid()) {
            throw new \RuntimeException(
                "Plan hash mismatch — file was hand-edited or corrupted. ".
                "Recompile with `tessera new --plan`.",
            );
        }

        return $plan;
    }

    /**
     * @param  list<PlanStep>  $steps
     */
    private function validateNoDuplicateIds(array $steps): void
    {
        $seen = [];
        foreach ($steps as $step) {
            if (isset($seen[$step->id])) {
                throw new \RuntimeException("Duplicate step id in plan: '{$step->id}'.");
            }
            $seen[$step->id] = true;
        }
    }

    /**
     * @param  list<PlanStep>  $steps
     */
    private function validateDependenciesResolve(array $steps): void
    {
        $ids = [];
        foreach ($steps as $step) {
            $ids[$step->id] = true;
        }

        foreach ($steps as $step) {
            foreach ($step->dependencies as $dep) {
                if (! isset($ids[$dep])) {
                    throw new \RuntimeException(
                        "Step '{$step->id}' depends on unknown step '{$dep}'.",
                    );
                }
            }
        }
    }
}
