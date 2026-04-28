<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

use Tessera\Installer\Schema\SchemaVersion;

/**
 * Outcome of a single gate check on a step's output.
 *
 * Gates evaluate AFTER the adapter has returned. They prove that the
 * AI actually produced what it claimed — the existence of a file, a
 * compileable PHP syntax, a passing lint. Without gates, an AI can say
 * "done!" and walk away from an empty directory.
 *
 * Severity decides what happens next:
 *   - `hard`: failure halts the step. Plan execution stops.
 *   - `soft`: failure is logged but execution continues. The step is
 *     still considered successful.
 */
final readonly class GateResult
{
    public const SEVERITY_HARD = 'hard';

    public const SEVERITY_SOFT = 'soft';

    public function __construct(
        public string $stepId,
        public string $gateType,
        public string $severity,
        public bool $passed,
        public string $message,
        public array $detail = [],
    ) {}

    public static function passed(string $stepId, string $gateType, string $severity, string $message = '', array $detail = []): self
    {
        return new self($stepId, $gateType, $severity, true, $message, $detail);
    }

    public static function failed(string $stepId, string $gateType, string $severity, string $message, array $detail = []): self
    {
        return new self($stepId, $gateType, $severity, false, $message, $detail);
    }

    public function halts(): bool
    {
        return ! $this->passed && $this->severity === self::SEVERITY_HARD;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => SchemaVersion::GATE_RESULT,
            'step' => $this->stepId,
            'gate' => $this->gateType,
            'severity' => $this->severity,
            'passed' => $this->passed,
            'message' => $this->message,
            'detail' => $this->detail,
        ];
    }
}
