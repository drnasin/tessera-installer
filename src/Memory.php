<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Tracks installer state and progress in .tessera/state.json.
 *
 * AI reads this to know where it is, what it's doing, and what's next.
 * State persists across retries and can be used for recovery.
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
            'project' => $projectName,
            'stack' => $stack,
            'requirements' => $requirements,
            'system' => $systemContext,
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'status' => 'in_progress',
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

    private function save(): void
    {
        if (! is_dir($this->stateDir) && ! mkdir($this->stateDir, 0755, true) && ! is_dir($this->stateDir)) {
            return;
        }

        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        // Atomic write: write to temp file, then rename.
        // Prevents corrupted state.json if the process crashes mid-write.
        $tmpFile = $this->stateFile.'.tmp';

        if (file_put_contents($tmpFile, $json) !== false) {
            rename($tmpFile, $this->stateFile);
        }
    }
}
