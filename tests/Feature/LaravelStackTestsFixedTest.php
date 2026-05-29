<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\CommandExecutor;
use Tessera\Installer\CommandResult;
use Tessera\Installer\Console;
use Tessera\Installer\EnvPolicy;
use Tessera\Installer\Memory;
use Tessera\Installer\Stacks\LaravelStack;
use Tessera\Installer\StepRunner;
use Tessera\Installer\ToolRouter;

/**
 * Honest quality-gate behaviour for LaravelStack::runAndFixTests() — issue #5.
 *
 * The run-and-fix-tests loop must never report success when generated-project
 * tests are still failing. These tests drive the pass/fail decision through an
 * injected CommandExecutor (the `php artisan test` subprocess routes through
 * Console::execSilentArgv → Console::setCommandExecutor()), so no real
 * processes are spawned.
 *
 * The AI fix step runs through an EMPTY ToolRouter: executeWithFallback()
 * short-circuits to "all tools unavailable" without proc_open(), so the loop's
 * outcome is governed solely by the injected test-run exit-code sequence.
 */
final class LaravelStackTestsFixedTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_laravelstack_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        Console::setCommandExecutor(null);
        $this->removeDir($this->tempDir);

        parent::tearDown();
    }

    #[Test]
    public function tests_passing_on_first_run_reports_success_and_no_failure(): void
    {
        // php artisan test exits 0 on the first run.
        Console::setCommandExecutor($this->sequenceExecutor([0]));

        [$stack, $steps, $memory] = $this->makeStack();

        $passed = $this->runLoop($stack, $steps, $memory);

        $this->assertTrue($passed, 'Loop should report success when tests pass on first run.');
        $this->assertTrue($memory->isStepDone('tests_fixed'), 'Step must be marked complete on a pass.');
        $this->assertFalse(
            $memory->hasFailedStep('tests_fixed'),
            'No failure should be recorded when tests pass.',
        );
        $this->assertSame('OK', $steps->getLog()['AI: Fix failing tests'] ?? null);
    }

    #[Test]
    public function tests_passing_after_an_ai_fix_reports_success(): void
    {
        // First run fails, AI "fixes" (no-op), second run passes.
        Console::setCommandExecutor($this->sequenceExecutor([1, 0]));

        [$stack, $steps, $memory] = $this->makeStack();

        $passed = $this->runLoop($stack, $steps, $memory);

        $this->assertTrue($passed, 'Loop should report success when tests pass after a fix attempt.');
        $this->assertTrue($memory->isStepDone('tests_fixed'), 'Step must be marked complete on an eventual pass.');
        $this->assertFalse(
            $memory->hasFailedStep('tests_fixed'),
            'No failure should be recorded once tests eventually pass.',
        );
        $this->assertSame('FIXED_BY_AI', $steps->getLog()['AI: Fix failing tests'] ?? null);
    }

    #[Test]
    public function tests_failing_after_all_attempts_is_not_reported_as_passed(): void
    {
        // Three test runs, all failing — the loop gives up after max attempts.
        $executor = $this->sequenceExecutor([1, 1, 1], output: "FAIL Tests\\Feature\\ExampleTest\n3 failed, 1 passed");
        Console::setCommandExecutor($executor);

        [$stack, $steps, $memory] = $this->makeStack();

        $passed = $this->runLoop($stack, $steps, $memory);

        $this->assertFalse($passed, 'Loop must NOT report success when tests keep failing.');

        // The failure is surfaced in the step summary vocabulary.
        $this->assertSame('FAILED', $steps->getLog()['AI: Fix failing tests'] ?? null);

        // The failure is recorded in Memory for honest resume / completion output.
        $this->assertTrue(
            $memory->hasFailedStep('tests_fixed'),
            'A failed-step record must exist so completion output does not claim tests passed.',
        );

        // The failing test output excerpt is preserved in state.json for post-mortem.
        $failedSteps = $memory->toArray()['failed_steps'] ?? [];
        $this->assertNotEmpty($failedSteps);
        $excerpt = $failedSteps[0]['error'] ?? '';
        $this->assertStringContainsString('3 failed', $excerpt);
        $this->assertStringContainsString('attempts', $excerpt);
    }

    #[Test]
    public function failing_then_passing_excerpt_is_not_persisted(): void
    {
        // Guard against a regression where a stale excerpt is recorded even
        // though the final run passed.
        Console::setCommandExecutor($this->sequenceExecutor([1, 1, 0]));

        [$stack, $steps, $memory] = $this->makeStack();

        $passed = $this->runLoop($stack, $steps, $memory);

        $this->assertTrue($passed);
        $this->assertFalse($memory->hasFailedStep('tests_fixed'));
        $this->assertEmpty($memory->toArray()['failed_steps'] ?? []);
    }

    #[Test]
    public function failure_path_marks_step_complete_so_resume_does_not_reloop(): void
    {
        // Crash-safety: the step must be marked complete on the failure path
        // (completion persisted AFTER the failure record) so a resume sees
        // isStepDone() === true and does NOT re-run the AI loop / burn tokens.
        Console::setCommandExecutor($this->sequenceExecutor([1, 1, 1]));

        [$stack, $steps, $memory] = $this->makeStack();

        $this->runLoop($stack, $steps, $memory);

        $this->assertTrue($memory->isStepDone('tests_fixed'), 'Step must be complete after a hard failure to avoid resume re-loop.');
        $this->assertTrue($memory->hasFailedStep('tests_fixed'), 'Failure must still be recorded alongside completion.');
    }

    #[Test]
    public function failure_state_persists_across_a_fresh_memory_instance(): void
    {
        // Simulate a process restart: write state, then read it back through a
        // brand-new Memory bound to the same dir (loads from .tessera/state.json).
        // This is what the scaffold resume branch relies on to stay honest —
        // both signals must survive the disk round-trip.
        Console::setCommandExecutor($this->sequenceExecutor([1, 1, 1]));

        [$stack, $steps, $memory] = $this->makeStack();
        $this->runLoop($stack, $steps, $memory);

        $reloaded = new Memory($this->tempDir);

        $this->assertTrue(
            $reloaded->isStepDone('tests_fixed'),
            'Completion must survive a process restart so resume does not re-run the loop.',
        );
        $this->assertTrue(
            $reloaded->hasFailedStep('tests_fixed'),
            'A resumed process must still see the recorded failure so completion output stays honest.',
        );
    }

    #[Test]
    public function repeated_failure_runs_do_not_duplicate_failed_step_records(): void
    {
        // Defensive: even if the loop somehow ran twice against the same Memory
        // (e.g. crash window between failStep and completeStep, then resume),
        // the dedup guard keeps a single failed_steps entry.
        Console::setCommandExecutor($this->sequenceExecutor([1, 1, 1, 1, 1, 1]));

        [$stack, $steps, $memory] = $this->makeStack();

        $this->runLoop($stack, $steps, $memory);
        $this->runLoop($stack, $steps, $memory);

        $failedSteps = array_filter(
            $memory->toArray()['failed_steps'] ?? [],
            static fn (array $s): bool => ($s['name'] ?? '') === 'tests_fixed',
        );

        $this->assertCount(1, $failedSteps, 'failed_steps must not accumulate duplicate tests_fixed entries.');
    }

    /**
     * @return array{0: LaravelStack, 1: StepRunner, 2: Memory}
     */
    private function makeStack(): array
    {
        // Empty router: AI fix step short-circuits without spawning processes.
        $router = new ToolRouter([]);
        $steps = new StepRunner($router, $this->tempDir);

        $memory = new Memory($this->tempDir);
        $memory->init('test-project', 'laravel', [], '');

        return [new LaravelStack, $steps, $memory];
    }

    private function runLoop(LaravelStack $stack, StepRunner $steps, Memory $memory): bool
    {
        ob_start();
        $result = $stack->runAndFixTests($steps, $memory, $this->tempDir, 1);
        ob_end_clean();

        return $result;
    }

    /**
     * Returns a CommandExecutor that yields the given exit codes in order for
     * each `php artisan test` invocation. Any non-test command returns 0.
     *
     * @param  list<int>  $exitCodes
     */
    private function sequenceExecutor(array $exitCodes, string $output = 'FAIL: 1 failed'): CommandExecutor
    {
        return new class($exitCodes, $output) implements CommandExecutor
        {
            private int $index = 0;

            /**
             * @param  list<int>  $exitCodes
             */
            public function __construct(
                private array $exitCodes,
                private string $output,
            ) {}

            public function run(
                array $argv,
                string $cwd,
                ?EnvPolicy $env = null,
                ?string $stdin = null,
                ?int $timeout = null,
            ): CommandResult {
                $isTestRun = in_array('test', $argv, true) && in_array('artisan', $argv, true);

                if (! $isTestRun) {
                    return new CommandResult(0, '', '', false, 0.01);
                }

                $exit = $this->exitCodes[$this->index] ?? 0;
                $this->index++;

                $stdout = $exit === 0 ? 'PASS Tests' : $this->output;

                return new CommandResult($exit, $stdout, '', false, 0.01);
            }
        };
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
