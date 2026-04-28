<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

use Tessera\Installer\AiResponse;

/**
 * Outcome of a single PlanStep execution.
 *
 * The PlanExecutor returns one of these per step. Aggregated into
 * PlanExecutionResult so post-mortems and `tessera analyze` can show
 * "step #4 failed because adapter timeout" without re-reading
 * events.jsonl line by line.
 */
final readonly class StepResult
{
    public function __construct(
        public string $stepId,
        public bool $success,
        public ?AiResponse $response,
        public string $adapterUsed,
        public ?string $modelUsed,
        public int $durationMs,
        public ?string $skipReason = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(
        string $stepId,
        AiResponse $response,
        string $adapterUsed,
        ?string $modelUsed,
        int $durationMs,
    ): self {
        return new self(
            stepId: $stepId,
            success: true,
            response: $response,
            adapterUsed: $adapterUsed,
            modelUsed: $modelUsed,
            durationMs: $durationMs,
        );
    }

    public static function failure(
        string $stepId,
        ?AiResponse $response,
        string $adapterUsed,
        ?string $modelUsed,
        int $durationMs,
        string $errorMessage,
    ): self {
        return new self(
            stepId: $stepId,
            success: false,
            response: $response,
            adapterUsed: $adapterUsed,
            modelUsed: $modelUsed,
            durationMs: $durationMs,
            errorMessage: $errorMessage,
        );
    }

    public static function skipped(string $stepId, string $reason): self
    {
        return new self(
            stepId: $stepId,
            success: true,
            response: null,
            adapterUsed: '',
            modelUsed: null,
            durationMs: 0,
            skipReason: $reason,
        );
    }

    public function wasSkipped(): bool
    {
        return $this->skipReason !== null;
    }
}
