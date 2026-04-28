<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

use Tessera\Installer\Events\EventLog;
use Tessera\Installer\Events\EventType;

/**
 * Runs the gate specs declared on a PlanStep against the project tree.
 *
 * Sprint 1 supports two gate types — `exists_any` and `exists_all` —
 * which together cover the StaticStack `verify` callback (was the
 * scaffold step able to write any of these files?). Sprint 2 adds
 * `php_syntax`, `glob count`, `phpstan level N`, and the full Codex
 * taxonomy (hard/soft/retryable/human-review).
 *
 * Every check emits an event regardless of outcome, so post-mortem
 * sees exactly what was checked and why it passed or failed.
 *
 * The evaluator is intentionally pure-PHP: no shell, no recursion into
 * untrusted directories, no glob patterns that escape the project tree.
 * `fail-loud` on unknown gate type — schemas reject unknown types at
 * load time so reaching this branch means a programming error.
 */
final class GateEvaluator
{
    public function __construct(private ?EventLog $eventLog = null) {}

    /**
     * @param  list<array<string, mixed>>  $gates
     * @return list<GateResult>
     */
    public function evaluate(string $stepId, array $gates, string $workingDir): array
    {
        $results = [];

        foreach ($gates as $gate) {
            $type = (string) ($gate['type'] ?? '');
            $severity = (string) ($gate['severity'] ?? GateResult::SEVERITY_HARD);

            if (! in_array($severity, [GateResult::SEVERITY_HARD, GateResult::SEVERITY_SOFT], true)) {
                $severity = GateResult::SEVERITY_HARD;
            }

            $result = match ($type) {
                'exists_any' => $this->checkExistsAny($stepId, $gate, $severity, $workingDir),
                'exists_all' => $this->checkExistsAll($stepId, $gate, $severity, $workingDir),
                default => GateResult::failed(
                    $stepId,
                    $type,
                    $severity,
                    "Unknown gate type '{$type}' (Sprint 2 introduces more types)",
                ),
            };

            $this->emit($result);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $gate
     */
    private function checkExistsAny(string $stepId, array $gate, string $severity, string $workingDir): GateResult
    {
        $patterns = $gate['patterns'] ?? $gate['paths'] ?? [];

        if (! is_array($patterns) || $patterns === []) {
            return GateResult::failed($stepId, 'exists_any', $severity, "exists_any gate needs a non-empty 'patterns' or 'paths' list.");
        }

        $checked = [];
        foreach ($patterns as $rawPattern) {
            $pattern = (string) $rawPattern;
            $checked[] = $pattern;

            $matches = $this->resolvePattern($pattern, $workingDir);

            if ($matches !== []) {
                return GateResult::passed(
                    $stepId,
                    'exists_any',
                    $severity,
                    "Found '{$pattern}' (matched ".count($matches).')',
                    ['matched_pattern' => $pattern, 'match_count' => count($matches)],
                );
            }
        }

        return GateResult::failed(
            $stepId,
            'exists_any',
            $severity,
            'None of the expected paths exist: '.implode(', ', $checked),
            ['checked' => $checked],
        );
    }

    /**
     * @param  array<string, mixed>  $gate
     */
    private function checkExistsAll(string $stepId, array $gate, string $severity, string $workingDir): GateResult
    {
        $patterns = $gate['patterns'] ?? $gate['paths'] ?? [];

        if (! is_array($patterns) || $patterns === []) {
            return GateResult::failed($stepId, 'exists_all', $severity, "exists_all gate needs a non-empty 'patterns' or 'paths' list.");
        }

        $missing = [];
        foreach ($patterns as $rawPattern) {
            $pattern = (string) $rawPattern;

            if ($this->resolvePattern($pattern, $workingDir) === []) {
                $missing[] = $pattern;
            }
        }

        if ($missing === []) {
            return GateResult::passed(
                $stepId,
                'exists_all',
                $severity,
                'All required paths present.',
                ['count' => count($patterns)],
            );
        }

        return GateResult::failed(
            $stepId,
            'exists_all',
            $severity,
            'Missing required paths: '.implode(', ', $missing),
            ['missing' => $missing],
        );
    }

    /**
     * Resolve a literal path or glob pattern relative to $workingDir.
     * Returns the list of matching real paths. The pattern may contain
     * `*` and `?`; `**` is NOT supported in v1 to avoid surprise
     * recursion. Refuses to resolve paths that escape $workingDir.
     *
     * @return list<string>
     */
    private function resolvePattern(string $pattern, string $workingDir): array
    {
        // Reject patterns that try to escape the working directory.
        if (str_contains($pattern, '..')) {
            return [];
        }

        $absolutePattern = rtrim($workingDir, '/\\').DIRECTORY_SEPARATOR.ltrim($pattern, '/\\');

        // Plain literal — no glob characters.
        if (! preg_match('/[*?\[]/', $pattern)) {
            return file_exists($absolutePattern) ? [$absolutePattern] : [];
        }

        $matches = glob($absolutePattern);

        return is_array($matches) ? array_values($matches) : [];
    }

    private function emit(GateResult $result): void
    {
        if ($this->eventLog === null) {
            return;
        }

        $type = $result->passed ? EventType::GatePass : EventType::GateFail;

        $this->eventLog->emit($type, $result->toArray());
    }
}
