<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

/**
 * Aggregate outcome of executing a CompiledPlan.
 *
 * Returned by PlanExecutor::execute(). Carries every StepResult plus a
 * top-level success flag (false if any step failed without a downstream
 * step recovering it). Sprint 2 adds gate result propagation here.
 */
final readonly class PlanExecutionResult
{
    /**
     * @param  list<StepResult>  $stepResults
     */
    public function __construct(
        public bool $success,
        public array $stepResults,
        public string $planHash,
        public int $totalDurationMs,
    ) {}

    public function failedSteps(): array
    {
        return array_values(array_filter(
            $this->stepResults,
            fn (StepResult $r): bool => ! $r->success,
        ));
    }

    public function completedSteps(): array
    {
        return array_values(array_filter(
            $this->stepResults,
            fn (StepResult $r): bool => $r->success && ! $r->wasSkipped(),
        ));
    }

    public function skippedSteps(): array
    {
        return array_values(array_filter(
            $this->stepResults,
            fn (StepResult $r): bool => $r->wasSkipped(),
        ));
    }
}
