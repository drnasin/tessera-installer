<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

use Tessera\Installer\Adapters\AdapterContext;
use Tessera\Installer\Adapters\AdapterRegistry;
use Tessera\Installer\Events\EventLog;
use Tessera\Installer\Events\EventType;
use Tessera\Installer\Memory;
use Tessera\Installer\ToolRouter;

/**
 * Executes a CompiledPlan step by step.
 *
 * Per round-3 consensus, the executor:
 *
 *   - Renders prompt templates through PromptRenderer immediately
 *     before each adapter call. Hash material is over the *template*;
 *     event payload also records the rendered-prompt hash and the
 *     context hash, so post-mortem can answer "did the bytes that hit
 *     the AI ever change?".
 *
 *   - Picks adapters via AdapterSelector, which preserves the legacy
 *     ToolRouter complexity routing during the v3.x → v4.0 transition.
 *
 *   - Evaluates gates AFTER the adapter call. Hard gate failure halts
 *     the step (and the plan); soft gate failure logs and continues.
 *
 *   - Honours `skippable`: a failed skippable step does NOT halt the
 *     plan — it emits `step.skipped` with the failure reason and the
 *     executor moves on.
 *
 *   - Writes Memory state BEFORE emitting terminal events. If a SIGINT
 *     lands between the two writes, audit lacks a success-event but
 *     Memory is correct — resume re-runs nothing wrongly. The reverse
 *     order would let events claim "done" while Memory says "in
 *     progress" → resume would re-run a completed step.
 *
 * Sprint 2 adds: file-output manifests, idempotency keys, cached AI
 * replay. The seams for those are already in place (events.jsonl with
 * three hashes, gate result records).
 */
final class PlanExecutor
{
    private GateEvaluator $gateEvaluator;

    private PromptRenderer $renderer;

    private AdapterSelector $selector;

    public function __construct(
        AdapterRegistry $adapters,
        private EventLog $eventLog,
        private int $defaultTimeout = 600,
        ?ToolRouter $router = null,
        ?GateEvaluator $gateEvaluator = null,
        ?PromptRenderer $renderer = null,
        private ?Memory $memory = null,
    ) {
        $this->selector = new AdapterSelector($adapters, $router);
        $this->gateEvaluator = $gateEvaluator ?? new GateEvaluator($eventLog);
        $this->renderer = $renderer ?? new PromptRenderer;
    }

    public function execute(CompiledPlan $plan, string $workingDir, RenderContext $context): PlanExecutionResult
    {
        if (! $plan->isHashValid()) {
            throw new \RuntimeException('Refusing to execute plan with invalid hash.');
        }

        $this->eventLog->emit(EventType::BuildStart, [
            'stack' => $plan->stack,
            'plan_hash' => $plan->planHash,
            'context_hash' => $context->hash(),
            'step_count' => count($plan->steps),
        ]);

        $startedAt = microtime(true);
        $stepResults = [];
        $allOk = true;

        try {
            foreach ($plan->inTopologicalOrder() as $step) {
                $result = $this->executeStep($step, $workingDir, $context);
                $stepResults[] = $result;

                if (! $result->success && ! $step->skippable) {
                    $allOk = false;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $allOk = false;

            $this->memory?->fail('Plan execution crashed: '.$e->getMessage());

            $this->eventLog->emit(EventType::BuildFail, [
                'plan_hash' => $plan->planHash,
                'total_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'step_count' => count($stepResults),
                'failed_count' => count(array_filter($stepResults, fn (StepResult $r): bool => ! $r->success)),
                'failure_reason' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            return new PlanExecutionResult(
                success: false,
                stepResults: $stepResults,
                planHash: $plan->planHash,
                totalDurationMs: (int) round((microtime(true) - $startedAt) * 1000),
            );
        }

        $totalMs = (int) round((microtime(true) - $startedAt) * 1000);

        // Memory before event: write terminal state first, then audit.
        if ($this->memory !== null) {
            $allOk ? $this->memory->complete() : $this->memory->fail('Plan execution halted on a non-skippable step.');
        }

        $this->eventLog->emit(
            $allOk ? EventType::BuildComplete : EventType::BuildFail,
            [
                'plan_hash' => $plan->planHash,
                'total_duration_ms' => $totalMs,
                'step_count' => count($stepResults),
                'failed_count' => count(array_filter($stepResults, fn (StepResult $r): bool => ! $r->success)),
            ],
        );

        return new PlanExecutionResult(
            success: $allOk,
            stepResults: $stepResults,
            planHash: $plan->planHash,
            totalDurationMs: $totalMs,
        );
    }

    private function executeStep(PlanStep $step, string $workingDir, RenderContext $context): StepResult
    {
        // Resume short-circuit: if Memory says this step already
        // completed in a prior run, return a synthetic success without
        // re-rendering or re-calling the adapter.
        if ($this->memory !== null && $this->memory->isStepDone($step->id)) {
            $this->eventLog->emit(EventType::StepSkip, [
                'step_id' => $step->id,
                'reason' => 'already-completed',
            ]);

            return StepResult::skipped($step->id, 'already-completed');
        }

        $adapter = $this->selector->select($step->complexity, $step->adapterHint);

        if ($adapter === null) {
            $this->eventLog->emit(EventType::StepFail, [
                'step_id' => $step->id,
                'reason' => 'No adapter available.',
            ]);

            $this->memory?->failStep($step->id, 'No adapter available.');

            return StepResult::failure(
                stepId: $step->id,
                response: null,
                adapterUsed: $step->adapterHint ?? '',
                modelUsed: $step->modelHint,
                durationMs: 0,
                errorMessage: 'No adapter available.',
            );
        }

        $resolvedModel = $this->selector->pickModel($step->complexity, $adapter, $step->modelHint);

        // Render the prompt template against the context — fail-loud on
        // missing variables. PromptRenderer wraps user-supplied values
        // in delimited DATA blocks (basic injection mitigation).
        try {
            $rendered = $this->renderer->render($step->prompt, $context);
        } catch (\Throwable $e) {
            $this->eventLog->emit(EventType::StepFail, [
                'step_id' => $step->id,
                'reason' => 'Prompt render failed: '.$e->getMessage(),
            ]);
            $this->memory?->failStep($step->id, $e->getMessage());

            return StepResult::failure(
                stepId: $step->id,
                response: null,
                adapterUsed: $adapter->name(),
                modelUsed: $resolvedModel,
                durationMs: 0,
                errorMessage: $e->getMessage(),
            );
        }

        $renderedHash = hash('sha256', $rendered);

        $this->memory?->startStep($step->id);

        $this->eventLog->emit(EventType::StepStart, [
            'step_id' => $step->id,
            'name' => $step->name,
            'complexity' => $step->complexity->value,
            'adapter_resolved' => $adapter->name(),
            'model_resolved' => $resolvedModel,
            'template_fingerprint' => $step->promptFingerprint,
            'context_hash' => $context->hash(),
            'rendered_prompt_hash' => $renderedHash,
            'skippable' => $step->skippable,
        ]);

        $startedAt = microtime(true);
        $adapterContext = new AdapterContext(
            workingDir: $workingDir,
            timeout: $step->timeout > 0 ? $step->timeout : $this->defaultTimeout,
            model: $resolvedModel,
            traceId: $this->eventLog->traceId(),
            eventLog: $this->eventLog,
            stepName: $step->id,
        );

        try {
            $response = $adapter->execute($rendered, $adapterContext);
        } catch (\Throwable $e) {
            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $errorMessage = 'Adapter crashed: '.$e->getMessage();

            $this->memory?->failStep($step->id, $errorMessage);

            $this->eventLog->emit(
                $step->skippable ? EventType::StepSkip : EventType::StepFail,
                [
                    'step_id' => $step->id,
                    'adapter' => $adapter->name(),
                    'duration_ms' => $duration,
                    'error_excerpt' => mb_substr($errorMessage, 0, 500),
                    'exception_class' => $e::class,
                    'skippable' => $step->skippable,
                ],
            );

            if ($step->skippable) {
                return StepResult::skipped($step->id, $errorMessage);
            }

            return StepResult::failure(
                stepId: $step->id,
                response: null,
                adapterUsed: $adapter->name(),
                modelUsed: $resolvedModel,
                durationMs: $duration,
                errorMessage: $errorMessage,
            );
        }
        $duration = (int) round((microtime(true) - $startedAt) * 1000);

        // Adapter call complete. Now evaluate gates.
        $gateResults = $this->gateEvaluator->evaluate($step->id, $step->gates, $workingDir);
        $hardFailure = $this->firstHardFailure($gateResults);
        $hardGateCount = count(array_filter(
            $gateResults,
            fn (GateResult $g): bool => $g->severity === GateResult::SEVERITY_HARD,
        ));

        // Special case: adapter returned non-zero, but every declared hard
        // gate passed. The wine-shop smoke run hit two flavours of this:
        //
        //   - exit 124 (timeout): Claude finished writing every file the
        //     gate required, but the long-running subprocess kept Windows'
        //     proc_close alive past our budget.
        //   - exit 1 (mid-stream error): Claude ran for 12 minutes, wrote
        //     the files the admin step needed, then bailed with no error
        //     message — likely a free-tier rate cap hitting just after the
        //     real work was done.
        //
        // In both cases the gate engine independently confirms the artefacts
        // are on disk. The gate is the contract; the exit code is a
        // technical signal that may or may not reflect the actual outcome.
        // Without this branch, resume loops forever — every retry hits the
        // same rate cap or pipe-handle ceiling.
        //
        // Note: this is intentionally conservative. The override only fires
        // when a hard gate is *declared and passed*. A step with no hard
        // gates still fails on a non-zero exit. Sprint 2's typed
        // failure_reason enum will replace this string heuristic with
        // something the executor can act on directly.
        $hasNonSuccessExit = ! $response->success;
        $allDeclaredHardGatesPassed = $hardFailure === null && $hardGateCount > 0;

        if ($hasNonSuccessExit && $allDeclaredHardGatesPassed) {
            $reasonLabel = $response->exitCode === 124
                ? 'timeout'
                : 'non-zero exit ('.$response->exitCode.')';

            $this->memory?->completeStep($step->id);

            $this->eventLog->emit(EventType::StepComplete, [
                'step_id' => $step->id,
                'adapter' => $adapter->name(),
                'duration_ms' => $duration,
                'output_size' => strlen($response->output),
                'gates_evaluated' => count($gateResults),
                'gates_passed' => count(array_filter($gateResults, fn (GateResult $g): bool => $g->passed)),
                'warning' => "Adapter returned {$reasonLabel}, but every hard gate passed — required artefacts are on disk.",
                'exit_code' => $response->exitCode,
            ]);

            return StepResult::success(
                stepId: $step->id,
                response: $response,
                adapterUsed: $adapter->name(),
                modelUsed: $resolvedModel,
                durationMs: $duration,
            );
        }

        if (! $response->success || $hardFailure !== null) {
            $errorMessage = $hardFailure !== null
                ? "Gate '{$hardFailure->gateType}' failed: {$hardFailure->message}"
                : ($response->error !== '' ? $response->error : 'Adapter returned non-zero exit.');

            $this->memory?->failStep($step->id, $errorMessage);

            $this->eventLog->emit(
                $step->skippable ? EventType::StepSkip : EventType::StepFail,
                [
                    'step_id' => $step->id,
                    'adapter' => $adapter->name(),
                    'duration_ms' => $duration,
                    'exit_code' => $response->exitCode,
                    'error_excerpt' => mb_substr($errorMessage, 0, 500),
                    'skippable' => $step->skippable,
                ],
            );

            if ($step->skippable) {
                return StepResult::skipped($step->id, $errorMessage);
            }

            return StepResult::failure(
                stepId: $step->id,
                response: $response,
                adapterUsed: $adapter->name(),
                modelUsed: $resolvedModel,
                durationMs: $duration,
                errorMessage: $errorMessage,
            );
        }

        // Memory write FIRST, then audit event — round-3 ordering decision.
        $this->memory?->completeStep($step->id);

        $this->eventLog->emit(EventType::StepComplete, [
            'step_id' => $step->id,
            'adapter' => $adapter->name(),
            'duration_ms' => $duration,
            'output_size' => strlen($response->output),
            'gates_evaluated' => count($gateResults),
            'gates_passed' => count(array_filter($gateResults, fn (GateResult $g): bool => $g->passed)),
        ]);

        return StepResult::success(
            stepId: $step->id,
            response: $response,
            adapterUsed: $adapter->name(),
            modelUsed: $resolvedModel,
            durationMs: $duration,
        );
    }

    /**
     * @param  list<GateResult>  $results
     */
    private function firstHardFailure(array $results): ?GateResult
    {
        foreach ($results as $result) {
            if ($result->halts()) {
                return $result;
            }
        }

        return null;
    }
}
