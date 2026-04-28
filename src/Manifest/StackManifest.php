<?php

declare(strict_types=1);

namespace Tessera\Installer\Manifest;

/**
 * Parsed and validated stack manifest — the authoring shape that compiles
 * into a CompiledPlan.
 *
 * A manifest is the developer-facing surface: human-edited YAML files in
 * `stacks/<name>.yaml` (built-ins) or wherever a third party drops one.
 * The compiler turns it into a hash-anchored plan; from there the
 * executor takes over.
 *
 * The `manifestVersion` field is the manifest schema version (currently
 * "1"). It is independent of `stack-name version` (e.g., laravel-v12
 * vs. laravel-v13) which lives in the `name` or `description`.
 */
final readonly class StackManifest
{
    /**
     * @param  list<ManifestStep>  $steps
     * @param  list<string>  $requires
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public array $steps,
        public string $manifestVersion = '1',
        public array $requires = [],
    ) {}

    public function step(string $id): ?ManifestStep
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        return null;
    }
}
