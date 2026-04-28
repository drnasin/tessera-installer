<?php

declare(strict_types=1);

namespace Tessera\Installer\Manifest;

use Tessera\Installer\Plan\CompiledPlan;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanStep;

/**
 * Bridges StackManifest (authoring shape) to CompiledPlan (executable artifact).
 *
 * Each ManifestStep becomes a PlanStep; the prompt body and version flow
 * through to PromptFingerprint. Requirements (user-supplied) are attached
 * to the compiled plan and become available to consumers (executor,
 * `tessera analyze`, post-mortems).
 *
 * The compiler does not run any AI. It produces a deterministic plan that
 * the user can inspect, diff, and only then execute.
 */
final class ManifestCompiler
{
    public function __construct(
        private PlanCompiler $planCompiler = new PlanCompiler,
    ) {}

    /**
     * @param  array<string, mixed>  $requirements
     */
    public function compile(StackManifest $manifest, array $requirements = []): CompiledPlan
    {
        $planSteps = array_map(
            fn (ManifestStep $step): PlanStep => PlanStep::build(
                id: $step->id,
                name: $step->name,
                complexity: $step->complexity,
                prompt: $step->prompt,
                promptVersion: $step->promptVersion,
                adapterHint: $step->adapterHint,
                modelHint: $step->modelHint,
                dependencies: $step->dependencies,
                gates: $step->gates,
                skippable: $step->skippable,
                timeout: $step->timeout,
            ),
            $manifest->steps,
        );

        return $this->planCompiler->compile(
            stack: $manifest->name,
            steps: $planSteps,
            requirements: $requirements,
        );
    }
}
