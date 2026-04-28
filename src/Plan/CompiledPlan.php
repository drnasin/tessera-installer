<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

use Tessera\Installer\Schema\SchemaVersion;

/**
 * A versioned, hash-anchored execution plan written to `.tessera/plan.json`.
 *
 * The compiled plan is the single source of truth for what `tessera new`
 * will do next. It is produced once (`tessera new --plan`) and consumed
 * many times (`tessera new --execute`, `tessera replay`, `tessera plan
 * diff`). Hand-editing `plan.json` is intentionally discouraged: edits
 * desync `plan_hash` from the actual step set, and the executor refuses
 * to run a plan whose hash does not match.
 *
 * `plan_hash` is sha256 of the deterministic JSON serialisation of the
 * step fingerprints in execution order. Adding/removing/reordering a
 * step or bumping any prompt version flips the hash; cosmetic edits to
 * the human-readable `name` field do not.
 *
 * Forward-compat: schema is `SchemaVersion::PLAN`. v2 readers MAY
 * tolerate v1 plans; v1 readers MUST refuse v2 plans (see
 * ArtifactValidator).
 */
final readonly class CompiledPlan
{
    /**
     * @param  list<PlanStep>  $steps
     * @param  array<string, mixed>  $requirements
     */
    public function __construct(
        public string $stack,
        public array $steps,
        public array $requirements,
        public string $planHash,
        public string $compiledAt,
        public string $compilerVersion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => SchemaVersion::PLAN,
            'stack' => $this->stack,
            'requirements' => $this->requirements,
            'compiled_at' => $this->compiledAt,
            'compiler_version' => $this->compilerVersion,
            'plan_hash' => $this->planHash,
            'steps' => array_map(fn (PlanStep $step): array => $step->toArray(), $this->steps),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $steps = [];

        foreach ($data['steps'] ?? [] as $stepData) {
            $steps[] = PlanStep::fromArray($stepData);
        }

        return new self(
            stack: (string) $data['stack'],
            steps: $steps,
            requirements: $data['requirements'] ?? [],
            planHash: (string) $data['plan_hash'],
            compiledAt: (string) $data['compiled_at'],
            compilerVersion: (string) $data['compiler_version'],
        );
    }

    /**
     * Find a step by id. Returns null if no step with that id is in the plan.
     */
    public function step(string $id): ?PlanStep
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Steps in topological order (dependencies first). Cycles raise
     * \RuntimeException — by construction the compiler rejects them, so
     * a cycle here means tampering after compile.
     *
     * @return list<PlanStep>
     */
    public function inTopologicalOrder(): array
    {
        $byId = [];
        foreach ($this->steps as $step) {
            $byId[$step->id] = $step;
        }

        $sorted = [];
        $visited = [];
        $visiting = [];

        $visit = function (string $id) use (&$visit, &$byId, &$sorted, &$visited, &$visiting): void {
            if (isset($visited[$id])) {
                return;
            }

            if (isset($visiting[$id])) {
                throw new \RuntimeException("Cycle detected in plan at step '{$id}'.");
            }

            if (! isset($byId[$id])) {
                throw new \RuntimeException("Plan references unknown step id '{$id}'.");
            }

            $visiting[$id] = true;

            foreach ($byId[$id]->dependencies as $dep) {
                $visit($dep);
            }

            unset($visiting[$id]);
            $visited[$id] = true;
            $sorted[] = $byId[$id];
        };

        foreach ($this->steps as $step) {
            $visit($step->id);
        }

        return $sorted;
    }

    /**
     * Recompute the plan hash from the current step set. The compiled
     * value (`planHash`) should always match this; if it does not, the
     * plan has been hand-edited or corrupted.
     */
    public function recomputeHash(): string
    {
        return self::computeHash($this->steps);
    }

    /**
     * @param  list<PlanStep>  $steps
     */
    public static function computeHash(array $steps): string
    {
        $material = array_map(
            fn (PlanStep $step): array => [
                'id' => $step->id,
                'fingerprint' => $step->promptFingerprint,
                'complexity' => $step->complexity->value,
                'dependencies' => $step->dependencies,
            ],
            $steps,
        );

        return hash('sha256', json_encode($material, JSON_UNESCAPED_SLASHES));
    }

    public function isHashValid(): bool
    {
        return hash_equals($this->planHash, $this->recomputeHash());
    }
}
