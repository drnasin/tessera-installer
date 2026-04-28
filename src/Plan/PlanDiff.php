<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

/**
 * Computes a *semantic* diff between two CompiledPlans.
 *
 * "What changed about the work that will run" — not a textual JSON diff.
 *
 * Categories produced:
 *   - addedSteps: ids present in `after` but not in `before`
 *   - removedSteps: ids present in `before` but not in `after`
 *   - promptChanged: ids whose prompt fingerprint differs
 *   - dependenciesChanged: ids whose dependency list differs
 *   - hintsChanged: ids whose adapter_hint or model_hint differs
 *   - complexityChanged: ids whose complexity changed
 *
 * If all categories are empty, the plans are equivalent — even if their
 * `compiled_at` timestamps differ.
 */
final class PlanDiff
{
    public static function between(CompiledPlan $before, CompiledPlan $after): self
    {
        $beforeIds = self::stepIds($before);
        $afterIds = self::stepIds($after);

        $added = array_values(array_diff($afterIds, $beforeIds));
        $removed = array_values(array_diff($beforeIds, $afterIds));
        $shared = array_values(array_intersect($beforeIds, $afterIds));

        $promptChanged = [];
        $dependenciesChanged = [];
        $hintsChanged = [];
        $complexityChanged = [];

        foreach ($shared as $id) {
            $b = $before->step($id);
            $a = $after->step($id);

            if ($b === null || $a === null) {
                continue;
            }

            if ($b->promptFingerprint !== $a->promptFingerprint) {
                $promptChanged[] = $id;
            }

            if ($b->dependencies !== $a->dependencies) {
                $dependenciesChanged[] = $id;
            }

            if ($b->adapterHint !== $a->adapterHint || $b->modelHint !== $a->modelHint) {
                $hintsChanged[] = $id;
            }

            if ($b->complexity !== $a->complexity) {
                $complexityChanged[] = $id;
            }
        }

        return new self(
            stackChanged: $before->stack !== $after->stack,
            beforeStack: $before->stack,
            afterStack: $after->stack,
            beforeHash: $before->planHash,
            afterHash: $after->planHash,
            addedSteps: $added,
            removedSteps: $removed,
            promptChanged: $promptChanged,
            dependenciesChanged: $dependenciesChanged,
            hintsChanged: $hintsChanged,
            complexityChanged: $complexityChanged,
        );
    }

    /**
     * @param  list<string>  $addedSteps
     * @param  list<string>  $removedSteps
     * @param  list<string>  $promptChanged
     * @param  list<string>  $dependenciesChanged
     * @param  list<string>  $hintsChanged
     * @param  list<string>  $complexityChanged
     */
    public function __construct(
        public readonly bool $stackChanged,
        public readonly string $beforeStack,
        public readonly string $afterStack,
        public readonly string $beforeHash,
        public readonly string $afterHash,
        public readonly array $addedSteps,
        public readonly array $removedSteps,
        public readonly array $promptChanged,
        public readonly array $dependenciesChanged,
        public readonly array $hintsChanged,
        public readonly array $complexityChanged,
    ) {}

    public function isEmpty(): bool
    {
        return ! $this->stackChanged
            && $this->addedSteps === []
            && $this->removedSteps === []
            && $this->promptChanged === []
            && $this->dependenciesChanged === []
            && $this->hintsChanged === []
            && $this->complexityChanged === [];
    }

    public function hashesMatch(): bool
    {
        return hash_equals($this->beforeHash, $this->afterHash);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stack_changed' => $this->stackChanged,
            'before_stack' => $this->beforeStack,
            'after_stack' => $this->afterStack,
            'before_hash' => $this->beforeHash,
            'after_hash' => $this->afterHash,
            'added_steps' => $this->addedSteps,
            'removed_steps' => $this->removedSteps,
            'prompt_changed' => $this->promptChanged,
            'dependencies_changed' => $this->dependenciesChanged,
            'hints_changed' => $this->hintsChanged,
            'complexity_changed' => $this->complexityChanged,
        ];
    }

    /**
     * @return list<string>
     */
    private static function stepIds(CompiledPlan $plan): array
    {
        return array_map(fn (PlanStep $s): string => $s->id, $plan->steps);
    }
}
