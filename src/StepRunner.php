<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Executes scaffold steps with AI-powered error recovery.
 *
 * Flow per step:
 * 1. Execute the step
 * 2. Verify it worked
 * 3. If failed → AI attempts to fix (up to maxRetries)
 * 4. If AI can't fix → ask user to fix manually, wait, then verify
 * 5. If user skips → continue to next step (if skippable)
 */
final class StepRunner
{
    private ToolRouter $router;

    private string $workingDir;

    private int $maxRetries;

    /** @var array<string, string> */
    private array $log = [];

    public function __construct(ToolRouter $router, string $workingDir, int $maxRetries = 2)
    {
        $this->router = $router;
        $this->workingDir = $workingDir;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Run a step with full error recovery.
     *
     * @param  string  $name  Step display name
     * @param  callable  $execute  fn(): bool — runs the step
     * @param  callable|null  $verify  fn(): string|null — returns null if OK, error message if not
     * @param  bool  $skippable  Can this step be skipped?
     * @param  string|null  $fixHint  Human-readable hint for manual fix
     * @return bool True if step succeeded (or was skipped), false if fatal.
     */
    public function run(
        string $name,
        callable $execute,
        ?callable $verify = null,
        bool $skippable = false,
        ?string $fixHint = null,
    ): bool {
        Console::line();
        Console::spinner($name);

        // Attempt 1: just run it
        $success = $this->attempt($execute);
        $error = $success ? $this->check($verify) : 'Command failed';

        if ($error === null) {
            Console::success($name);
            $this->log[$name] = 'OK';

            return true;
        }

        // Retry with AI fix attempts
        for ($i = 1; $i <= $this->maxRetries; $i++) {
            Console::warn("  Failed: {$error}");
            Console::spinner("  AI attempting fix (attempt {$i}/{$this->maxRetries})...");

            $fixed = $this->aiFix($name, $error, $fixHint);

            if ($fixed) {
                // Re-run the step after AI fix
                $success = $this->attempt($execute);
                $error = $success ? $this->check($verify) : 'Command failed after fix';

                if ($error === null) {
                    Console::success("{$name} (fixed by AI)");
                    $this->log[$name] = 'FIXED_BY_AI';

                    return true;
                }
            }
        }

        // AI couldn't fix it — ask the user
        return $this->fallbackToUser($name, $error, $execute, $verify, $skippable, $fixHint);
    }

    /**
     * Run a shell command as a step (convenience wrapper).
     */
    public function runCommand(
        string $name,
        string $command,
        ?callable $verify = null,
        bool $skippable = false,
        ?string $fixHint = null,
    ): bool {
        return $this->run(
            name: $name,
            execute: fn (): bool => Console::exec($command, $this->workingDir) === 0,
            verify: $verify,
            skippable: $skippable,
            fixHint: $fixHint,
        );
    }

    /**
     * Install packages one by one, retrying failures individually.
     *
     * @param  array<string>  $packages
     */
    public function installPackages(string $name, array $packages, bool $dev = false): bool
    {
        Console::line();
        Console::spinner($name);

        $devFlag = $dev ? ' --dev' : '';
        $failed = [];

        // Try all at once first (faster)
        $allPackages = implode(' ', $packages);
        $exit = Console::exec(
            "composer require{$devFlag} {$allPackages} --no-interaction",
            $this->workingDir,
        );

        if ($exit === 0) {
            Console::success($name);
            $this->log[$name] = 'OK';

            return true;
        }

        // Bulk install failed — try one by one (skip autoload, dump once at end)
        Console::warn('  Bulk install failed. Installing packages individually...');

        foreach ($packages as $package) {
            $result = Console::execSilent(
                "composer require{$devFlag} {$package} --no-interaction --no-autoloader",
                $this->workingDir,
            );

            if ($result['exit'] === 0) {
                Console::success("  {$package}");
            } else {
                Console::fail("  {$package}");
                $failed[] = ['package' => $package, 'error' => $result['output']];
            }
        }

        // Generate autoload once for all individually installed packages
        Console::spinner('  Generating autoload...');
        Console::execSilent('composer dump-autoload', $this->workingDir);

        if (empty($failed)) {
            Console::success($name);
            $this->log[$name] = 'OK';

            return true;
        }

        // Try AI fix for each failed package
        foreach ($failed as $i => $fail) {
            Console::spinner("  AI fixing {$fail['package']}...");

            $fixPrompt = "composer require{$devFlag} {$fail['package']} failed with error:\n{$fail['error']}\n\n"
                ."Fix the issue and install the package. Working directory: {$this->workingDir}\n"
                ."If the package doesn't exist or is incompatible, find an alternative or skip it.";

            $selection = $this->router->resolve(Complexity::SIMPLE);
            $response = $selection->tool->execute($fixPrompt, $this->workingDir, 120, $selection->model);

            if ($response->success) {
                // Verify it's installed now
                $check = Console::execSilent(
                    "composer show {$fail['package']} 2>&1",
                    $this->workingDir,
                );

                if ($check['exit'] === 0) {
                    Console::success("  {$fail['package']} (fixed by AI)");
                    unset($failed[$i]);
                }
            }
        }

        if (empty($failed)) {
            Console::success($name);
            $this->log[$name] = 'FIXED_BY_AI';

            return true;
        }

        // Still have failures — ask user
        $failedNames = array_column($failed, 'package');
        Console::warn('  Could not install: '.implode(', ', $failedNames));

        $instructions = "Try installing manually:\n";
        foreach ($failed as $fail) {
            $instructions .= "  composer require{$devFlag} {$fail['package']}\n";
        }

        return $this->askUserToFix(
            $name,
            $instructions,
            fn (): bool => true, // No re-execute needed
            null,
            true, // Skippable — missing optional packages shouldn't block
        );
    }

    /**
     * Run an AI prompt as a step.
     */
    public function runAi(
        string $name,
        string $prompt,
        ?callable $verify = null,
        bool $skippable = false,
        int $timeout = 300,
        Complexity $complexity = Complexity::MEDIUM,
    ): bool {
        return $this->run(
            name: $name,
            execute: function () use ($name, $prompt, $timeout, $complexity): bool {
                $selection = $this->router->resolve($complexity);
                $toolName = $selection->tool->name();
                $modelName = $selection->model ? basename($selection->model) : 'default';
                Console::line("  Using: {$toolName} ({$modelName})");

                $startTime = time();
                $response = $selection->tool->execute($prompt, $this->workingDir, $timeout, $selection->model);
                $elapsed = time() - $startTime;
                $elapsedMin = round($elapsed / 60, 1);

                if ($response->success) {
                    // Show AI output (trimmed)
                    $output = $response->output;
                    if (strlen($output) > 500) {
                        $output = substr($output, 0, 500).'...';
                    }
                    if ($output !== '') {
                        Console::line("  {$output}");
                    }

                    if ($elapsed > 30) {
                        Console::line("  ({$elapsedMin} min)");
                    }

                    return true;
                }

                // Provide clear feedback about what went wrong
                if ($response->exitCode === 124) {
                    Console::line();
                    Console::error("  TIMEOUT: Step '{$name}' took longer than {$timeout}s and was stopped.");
                    Console::line('  This usually means AI got stuck or the task was too complex.');
                    Console::line('  The step will be retried. If it keeps failing, you can skip it.');
                } else {
                    Console::warn("  AI error on '{$name}': {$response->error}");
                    if ($elapsed > 5) {
                        Console::line("  (ran for {$elapsedMin} min before failing)");
                    }
                }

                return false;
            },
            verify: $verify,
            skippable: $skippable,
        );
    }

    /**
     * Get the log of all steps and their outcomes.
     *
     * @return array<string, string>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    /**
     * Print a summary of all steps.
     */
    public function printSummary(): void
    {
        Console::line();
        Console::bold('Step Summary:');

        foreach ($this->log as $step => $status) {
            match ($status) {
                'OK' => Console::success("  {$step}"),
                'FIXED_BY_AI' => Console::info("  {$step} (AI fixed)"),
                'FIXED_BY_USER' => Console::info("  {$step} (user fixed)"),
                'SKIPPED' => Console::warn("  {$step} (skipped)"),
                default => Console::fail("  {$step} ({$status})"),
            };
        }
    }

    private function attempt(callable $execute): bool
    {
        try {
            return $execute();
        } catch (\Throwable $e) {
            Console::warn("  Exception: {$e->getMessage()}");

            return false;
        }
    }

    private function check(?callable $verify): ?string
    {
        if ($verify === null) {
            return null;
        }

        try {
            return $verify();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Ask AI to fix the problem.
     */
    private function aiFix(string $stepName, string $error, ?string $hint): bool
    {
        $prompt = "Step '{$stepName}' failed with error:\n{$error}\n\n"
            ."Working directory: {$this->workingDir}\n"
            ."Fix the issue so the step can succeed.\n";

        if ($hint) {
            $prompt .= "Hint: {$hint}\n";
        }

        // Fixes are straightforward — use a fast model
        $selection = $this->router->resolve(Complexity::SIMPLE);
        Console::line("  Fix using: {$selection->tool->name()}");
        $response = $selection->tool->execute($prompt, $this->workingDir, 120, $selection->model);

        if (! $response->success && $response->error) {
            Console::warn("  AI fix failed: {$response->error}");
        }

        return $response->success;
    }

    /**
     * Fall back to asking the user to fix manually.
     */
    private function fallbackToUser(
        string $name,
        string $error,
        callable $execute,
        ?callable $verify,
        bool $skippable,
        ?string $fixHint,
    ): bool {
        $instructions = $fixHint ?? 'Fix the error and try again.';

        return $this->askUserToFix($name, $instructions, $execute, $verify, $skippable);
    }

    /**
     * Show instructions, wait for user, then verify.
     */
    private function askUserToFix(
        string $name,
        string $instructions,
        callable $execute,
        ?callable $verify,
        bool $skippable,
        int $depth = 0,
    ): bool {
        if ($depth >= 5) {
            Console::error("  Step '{$name}' failed after {$depth} manual fix attempts.");
            $this->log[$name] = 'FAILED';

            return $skippable;
        }

        Console::line();
        Console::warn("AI couldn't fix: {$name}");
        Console::warn("Error: {$error}");
        Console::line();
        Console::bold('Please fix manually:');
        Console::line($instructions);
        Console::line();

        if ($skippable) {
            $choice = Console::choice('What would you like to do?', [
                'I fixed it — continue',
                'Skip this step',
                'Abort installation',
            ]);
        } else {
            $choice = Console::choice('What would you like to do?', [
                'I fixed it — continue',
                'Abort installation',
            ]);
        }

        // Map choice to action
        if ($skippable) {
            match ($choice) {
                0 => null, // User fixed, continue below
                1 => null, // Skip
                2 => null, // Abort
                default => null,
            };

            if ($choice === 1) {
                Console::warn("  Skipping: {$name}");
                $this->log[$name] = 'SKIPPED';

                return true;
            }

            if ($choice === 2) {
                $this->log[$name] = 'ABORTED';

                return false;
            }
        } else {
            if ($choice === 1) {
                $this->log[$name] = 'ABORTED';

                return false;
            }
        }

        // User says they fixed it — verify
        $success = $this->attempt($execute);
        $error = $success ? $this->check($verify) : 'Command still failing';

        if ($error === null) {
            Console::success("{$name} (fixed by user)");
            $this->log[$name] = 'FIXED_BY_USER';

            return true;
        }

        // Still failing — let user try again
        Console::fail("  Still failing: {$error}");

        return $this->askUserToFix($name, "Previous fix didn't work.\n{$error}", $execute, $verify, $skippable, $depth + 1);
    }
}
