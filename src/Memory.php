<?php

declare(strict_types=1);

namespace Tessera\Installer;

use Tessera\Installer\Schema\SchemaVersion;

/**
 * Tracks installer state and progress in .tessera/state.json.
 *
 * AI reads this to know where it is, what it's doing, and what's next.
 * State persists across retries and can be used for recovery.
 *
 * Every state.json now carries a `schema` field (SchemaVersion::STATE) so
 * future readers can refuse unknown shapes rather than guessing. Older
 * state files (pre-schema) load fine — the field is added on next save.
 */
final class Memory
{
    private string $stateDir;

    private string $stateFile;

    /** @var array<string, mixed> */
    private array $state;

    public function __construct(string $projectDir)
    {
        $this->stateDir = $projectDir.DIRECTORY_SEPARATOR.'.tessera';
        $this->stateFile = $this->stateDir.DIRECTORY_SEPARATOR.'state.json';
        $this->state = $this->load();
    }

    /**
     * Initialize memory for a new project.
     *
     * @param  array<string, mixed>  $requirements
     */
    public function init(string $projectName, string $stack, array $requirements, string $systemContext): void
    {
        $this->state = [
            'schema' => SchemaVersion::STATE,
            'project' => $projectName,
            'stack' => $stack,
            'requirements' => $requirements,
            'system' => $systemContext,
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'status' => 'in_progress',
            'trace_id' => bin2hex(random_bytes(8)),
            'current_step' => null,
            'completed_steps' => [],
            'failed_steps' => [],
            'skipped_steps' => [],
            'decisions' => [],
            'installed_dependencies' => [],
            'created_files' => [],
            'notes' => [],
        ];

        $this->save();
    }

    /**
     * Update requirements and system context without wiping progress.
     * Used when resuming a previous install.
     *
     * @param  array<string, mixed>  $requirements
     */
    public function updateContext(array $requirements, string $systemContext): void
    {
        $this->state['requirements'] = $requirements;
        $this->state['system'] = $systemContext;
        $this->state['status'] = 'in_progress';
        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * Check if memory has existing state (loaded from disk).
     */
    public function hasState(): bool
    {
        return ! empty($this->state);
    }

    public function traceId(): ?string
    {
        $traceId = $this->state['trace_id'] ?? null;

        return is_string($traceId) && $traceId !== '' ? $traceId : null;
    }

    /**
     * Record the start of a step.
     */
    public function startStep(string $step): void
    {
        $this->state['current_step'] = $step;
        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * Check if a step was already completed (for resume support).
     */
    public function isStepDone(string $step): bool
    {
        foreach ($this->state['completed_steps'] ?? [] as $completed) {
            if (($completed['name'] ?? '') === $step) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a step has a recorded failure (for honest resume / completion
     * reporting). A step can be both completed AND failed: "completed" means
     * the loop ran to its end, "failed" records that the outcome was bad.
     * See issue #5 — `tests_fixed` is marked complete even when generated
     * project tests still fail, but the failure is tracked here so nothing
     * claims tests passed when they did not.
     */
    public function hasFailedStep(string $step): bool
    {
        foreach ($this->state['failed_steps'] ?? [] as $failed) {
            if (($failed['name'] ?? '') === $step) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a completed step.
     */
    public function completeStep(string $step): void
    {
        $this->state['completed_steps'][] = [
            'name' => $step,
            'completed_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->state['current_step'] === $step) {
            $this->state['current_step'] = null;
        }

        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * Record a failed step.
     */
    public function failStep(string $step, string $error): void
    {
        $this->state['failed_steps'][] = [
            'name' => $step,
            'error' => $error,
            'failed_at' => date('Y-m-d H:i:s'),
        ];

        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * Record a skipped step.
     */
    public function skipStep(string $step, string $reason = ''): void
    {
        $this->state['skipped_steps'][] = [
            'name' => $step,
            'reason' => $reason,
            'skipped_at' => date('Y-m-d H:i:s'),
        ];

        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * Record a decision AI made.
     */
    public function recordDecision(string $what, string $decision, string $reason = ''): void
    {
        $this->state['decisions'][] = [
            'what' => $what,
            'decision' => $decision,
            'reason' => $reason,
            'decided_at' => date('Y-m-d H:i:s'),
        ];

        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * Record an installed dependency.
     */
    public function recordInstall(string $tool, string $version): void
    {
        $this->state['installed_dependencies'][] = [
            'tool' => $tool,
            'version' => $version,
            'installed_at' => date('Y-m-d H:i:s'),
        ];

        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * Add a note (AI can leave breadcrumbs for itself).
     */
    public function addNote(string $note): void
    {
        $this->state['notes'][] = [
            'text' => $note,
            'added_at' => date('Y-m-d H:i:s'),
        ];

        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * Mark the project as complete.
     */
    public function complete(): void
    {
        $this->state['status'] = 'complete';
        $this->state['current_step'] = null;
        $this->state['completed_at'] = date('Y-m-d H:i:s');
        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * Mark the project as failed.
     */
    public function fail(string $reason): void
    {
        $this->state['status'] = 'failed';
        $this->state['failure_reason'] = $reason;
        $this->state['updated_at'] = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * Build a context summary for AI prompts.
     * AI reads this to understand current state and history.
     */
    public function buildAiContext(): string
    {
        if (empty($this->state)) {
            return '';
        }

        $completed = array_map(
            fn (array $s): string => $s['name'],
            $this->state['completed_steps'] ?? [],
        );

        $failed = array_map(
            fn (array $s): string => "{$s['name']}: {$s['error']}",
            $this->state['failed_steps'] ?? [],
        );

        $decisions = array_map(
            fn (array $d): string => "{$d['what']}: {$d['decision']}".($d['reason'] ? " ({$d['reason']})" : ''),
            $this->state['decisions'] ?? [],
        );

        $notes = array_map(
            fn (array $n): string => $n['text'],
            $this->state['notes'] ?? [],
        );

        $context = "PROJECT STATE:\n";
        $context .= "- Project: {$this->state['project']}\n";
        $context .= "- Stack: {$this->state['stack']}\n";
        $context .= "- Status: {$this->state['status']}\n";

        if ($this->state['current_step']) {
            $context .= "- Current step: {$this->state['current_step']}\n";
        }

        if (! empty($completed)) {
            $context .= '- Completed: '.implode(', ', $completed)."\n";
        }

        if (! empty($failed)) {
            $context .= '- Failed: '.implode('; ', $failed)."\n";
        }

        if (! empty($decisions)) {
            $context .= '- Decisions made: '.implode('; ', $decisions)."\n";
        }

        if (! empty($notes)) {
            $context .= '- Notes: '.implode('; ', $notes)."\n";
        }

        return $context;
    }

    /**
     * Get raw state array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->state;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if (! is_file($this->stateFile)) {
            return [];
        }

        $content = file_get_contents($this->stateFile);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Atomic write with cross-process lock.
     *
     *   - Unique tmp filename (pid + random) so parallel writers don't
     *     stomp on each other's staging file.
     *   - flock() on a sibling lockfile serialises concurrent writers.
     *   - On POSIX, rename() overwrites atomically. On Windows, rename()
     *     fails if the target exists, so we unlink-then-rename under the
     *     lock. A crash between unlink and rename leaves no state.json
     *     (load() treats missing file as empty state), which is recoverable,
     *     versus a corrupted JSON which is not.
     */
    private function save(): void
    {
        if (! is_dir($this->stateDir) && ! mkdir($this->stateDir, 0755, true) && ! is_dir($this->stateDir)) {
            return;
        }

        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        $lockPath = $this->stateFile.'.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if ($lockHandle === false) {
            // Can't create the lockfile — fall back to best-effort write.
            $this->writeWithoutLock($json);

            return;
        }

        try {
            if (! flock($lockHandle, LOCK_EX)) {
                // Couldn't acquire the lock — still attempt the write rather
                // than losing state, but without serialisation guarantees.
                $this->writeWithoutLock($json);

                return;
            }

            $this->writeAtomic($json);

            flock($lockHandle, LOCK_UN);
        } finally {
            fclose($lockHandle);
        }
    }

    private function writeAtomic(string $json): void
    {
        $tmpFile = $this->stateFile.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($tmpFile, $json) === false) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && is_file($this->stateFile)) {
            // Windows rename() refuses to overwrite; remove first under the lock.
            // The worst-case crash window (state.json missing) is strictly better
            // than a corrupted state.json that load() cannot parse.
            @unlink($this->stateFile);
        }

        if (! @rename($tmpFile, $this->stateFile)) {
            // rename failed — fall back to copy+unlink so we at least persist.
            if (@copy($tmpFile, $this->stateFile)) {
                @unlink($tmpFile);
            }
        }
    }

    private function writeWithoutLock(string $json): void
    {
        // Degenerate path — no lockfile possible. Still use unique tmp so
        // two simultaneous writers don't fight over the same staging file.
        $this->writeAtomic($json);
    }
}
