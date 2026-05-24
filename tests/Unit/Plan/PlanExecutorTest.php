<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Adapters\AdapterContext;
use Tessera\Installer\Adapters\AdapterInterface;
use Tessera\Installer\Adapters\AdapterRegistry;
use Tessera\Installer\AiResponse;
use Tessera\Installer\Complexity;
use Tessera\Installer\Events\EventLog;
use Tessera\Installer\Events\EventType;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanExecutor;
use Tessera\Installer\Plan\PlanStep;
use Tessera\Installer\Plan\RenderContext;

final class PlanExecutorTest extends TestCase
{
    private string $tmpDir;

    private string $logPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera-exec-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir.'/.tessera', 0755, true);
        $this->logPath = $this->tmpDir.'/.tessera/events.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            @unlink($this->logPath);
        }
        if (is_dir($this->tmpDir.'/.tessera')) {
            @rmdir($this->tmpDir.'/.tessera');
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function executes_steps_in_topological_order(): void
    {
        $executionOrder = [];
        $adapter = $this->fakeAdapter('fake', function (string $prompt) use (&$executionOrder): AiResponse {
            $executionOrder[] = $prompt;

            return new AiResponse(true, "result");
        });

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'A: {{description}}', adapterHint: 'fake'),
            PlanStep::build('b', 'B', Complexity::SIMPLE, 'B: {{description}}', adapterHint: 'fake', dependencies: ['a']),
            PlanStep::build('c', 'C', Complexity::SIMPLE, 'C: {{description}}', adapterHint: 'fake', dependencies: ['b']),
        ]);

        $result = $executor->execute($plan, $this->tmpDir, new RenderContext(description: 'demo'));

        $this->assertTrue($result->success);
        $this->assertCount(3, $executionOrder);
        $this->assertStringContainsString('A:', $executionOrder[0]);
        $this->assertStringContainsString('B:', $executionOrder[1]);
        $this->assertStringContainsString('C:', $executionOrder[2]);
    }

    #[Test]
    public function rendered_prompt_substitutes_context_values(): void
    {
        $captured = '';
        $adapter = $this->fakeAdapter('fake', function (string $prompt) use (&$captured): AiResponse {
            $captured = $prompt;

            return new AiResponse(true, 'ok');
        });

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'Hello {{description}}', adapterHint: 'fake'),
        ]);

        $executor->execute($plan, $this->tmpDir, new RenderContext(description: 'WORLD'));

        $this->assertStringContainsString('WORLD', $captured);
        $this->assertStringContainsString('USER_DATA name="description"', $captured);
    }

    #[Test]
    public function step_event_payload_carries_three_hashes(): void
    {
        $adapter = $this->fakeAdapter('fake', fn () => new AiResponse(true, 'ok'));

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'Hello {{description}}', adapterHint: 'fake'),
        ]);

        $executor->execute($plan, $this->tmpDir, new RenderContext(description: 'X'));

        $stepStarts = array_filter($this->readEvents(), fn ($e) => $e['type'] === 'step.start');
        $stepStart = reset($stepStarts);

        $this->assertNotFalse($stepStart);
        $this->assertArrayHasKey('template_fingerprint', $stepStart['payload']);
        $this->assertArrayHasKey('context_hash', $stepStart['payload']);
        $this->assertArrayHasKey('rendered_prompt_hash', $stepStart['payload']);
    }

    #[Test]
    public function skippable_failure_does_not_halt_plan(): void
    {
        $execOrder = [];
        $adapter = $this->fakeAdapter('fake', function (string $prompt) use (&$execOrder): AiResponse {
            $execOrder[] = $prompt;
            if (str_contains($prompt, 'B-prompt')) {
                return new AiResponse(false, '', 'forced fail', 1);
            }

            return new AiResponse(true, 'ok');
        });

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'A-prompt', adapterHint: 'fake'),
            PlanStep::build('b', 'B', Complexity::SIMPLE, 'B-prompt', adapterHint: 'fake', dependencies: ['a'], skippable: true),
            PlanStep::build('c', 'C', Complexity::SIMPLE, 'C-prompt', adapterHint: 'fake', dependencies: ['b']),
        ]);

        $result = $executor->execute($plan, $this->tmpDir, new RenderContext);

        $this->assertTrue($result->success, 'Plan should succeed despite B failing — skippable');
        $this->assertCount(3, $execOrder, 'All three steps should run');
    }

    #[Test]
    public function hard_gate_failure_halts_step(): void
    {
        $adapter = $this->fakeAdapter('fake', fn () => new AiResponse(true, 'ok'));

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build(
                'a',
                'A',
                Complexity::SIMPLE,
                'prompt',
                adapterHint: 'fake',
                gates: [['type' => 'exists_all', 'severity' => 'hard', 'paths' => ['will-not-exist.html']]],
            ),
        ]);

        $result = $executor->execute($plan, $this->tmpDir, new RenderContext);

        $this->assertFalse($result->success);
        $this->assertCount(1, $result->failedSteps());
    }

    #[Test]
    public function soft_gate_failure_does_not_halt(): void
    {
        $adapter = $this->fakeAdapter('fake', fn () => new AiResponse(true, 'ok'));

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build(
                'a',
                'A',
                Complexity::SIMPLE,
                'prompt',
                adapterHint: 'fake',
                gates: [['type' => 'exists_all', 'severity' => 'soft', 'paths' => ['will-not-exist.html']]],
            ),
        ]);

        $result = $executor->execute($plan, $this->tmpDir, new RenderContext);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function emits_build_and_step_events(): void
    {
        $adapter = $this->fakeAdapter('fake', fn () => new AiResponse(true, 'ok'));

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'body', adapterHint: 'fake'),
        ]);

        $executor->execute($plan, $this->tmpDir, new RenderContext);

        $types = array_map(fn ($e) => $e['type'], $this->readEvents());

        $this->assertContains(EventType::BuildStart->value, $types);
        $this->assertContains(EventType::StepStart->value, $types);
        $this->assertContains(EventType::StepComplete->value, $types);
        $this->assertContains(EventType::BuildComplete->value, $types);
    }

    #[Test]
    public function refuses_to_execute_plan_with_tampered_hash(): void
    {
        $adapter = $this->fakeAdapter('fake', fn () => new AiResponse(true, 'ok'));
        $executor = $this->makeExecutor($adapter);

        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'body', adapterHint: 'fake'),
        ]);

        $tampered = new \Tessera\Installer\Plan\CompiledPlan(
            stack: $plan->stack,
            steps: $plan->steps,
            requirements: $plan->requirements,
            planHash: 'definitely-not-the-real-hash',
            compiledAt: $plan->compiledAt,
            compilerVersion: $plan->compilerVersion,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid hash');

        $executor->execute($tampered, $this->tmpDir, new RenderContext);
    }

    #[Test]
    public function timeout_with_all_hard_gates_passing_treated_as_success(): void
    {
        // Real-world failure mode: Claude finished writing all promised files,
        // but the long-running subprocess kept proc_close alive past our
        // budget. Adapter returns exit 124 (timeout) but the hard gate finds
        // every required file on disk. Without this branch, resume loops.
        $marker = $this->tmpDir.'/marker.txt';
        @file_put_contents($marker, 'AI wrote me before timeout');

        $adapter = $this->fakeAdapter('fake', fn (): AiResponse => new AiResponse(
            success: false,
            output: '',
            error: 'Timeout after 1s',
            exitCode: 124,
        ));

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build(
                'a',
                'A',
                Complexity::SIMPLE,
                'body',
                adapterHint: 'fake',
                gates: [['type' => 'exists_all', 'severity' => 'hard', 'paths' => ['marker.txt']]],
            ),
        ]);

        $result = $executor->execute($plan, $this->tmpDir, new RenderContext);

        @unlink($marker);

        $this->assertTrue($result->success, 'Build should succeed when timeout is overridden by gate-pass.');
        $this->assertCount(1, $result->completedSteps());
        $this->assertCount(0, $result->failedSteps());

        $events = $this->readEvents();
        $stepCompletes = array_filter($events, fn ($e) => $e['type'] === 'step.complete');
        $this->assertCount(1, $stepCompletes);

        $completion = array_values($stepCompletes)[0];
        $this->assertArrayHasKey('warning', $completion['payload']);
        $this->assertStringContainsString('timeout', $completion['payload']['warning']);
        $this->assertSame(124, $completion['payload']['exit_code']);
    }

    #[Test]
    public function generic_nonzero_exit_with_all_hard_gates_passing_treated_as_success(): void
    {
        // The other half of the override: non-zero exit that is NOT a
        // timeout. The wine-shop admin step hit this — Claude exited 1
        // mid-stream after writing all required files (likely a free-tier
        // rate cap firing late in the run). Gate is truth; exit code is a
        // signal that may or may not reflect the actual outcome.
        $marker = $this->tmpDir.'/admin-marker.php';
        @file_put_contents($marker, '<?php // admin file from before the rate cap');

        $adapter = $this->fakeAdapter('fake', fn (): AiResponse => new AiResponse(
            success: false,
            output: '',
            error: '',
            exitCode: 1,
        ));

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build(
                'a',
                'A',
                Complexity::SIMPLE,
                'body',
                adapterHint: 'fake',
                gates: [['type' => 'exists_any', 'severity' => 'hard', 'patterns' => ['admin-marker.php']]],
            ),
        ]);

        $result = $executor->execute($plan, $this->tmpDir, new RenderContext);

        @unlink($marker);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->completedSteps());

        $events = $this->readEvents();
        $stepCompletes = array_filter($events, fn ($e) => $e['type'] === 'step.complete');
        $completion = array_values($stepCompletes)[0];
        $this->assertArrayHasKey('warning', $completion['payload']);
        $this->assertStringContainsString('non-zero exit (1)', $completion['payload']['warning']);
    }

    #[Test]
    public function timeout_without_hard_gates_remains_a_failure(): void
    {
        // Sanity check: the special case requires *at least one* hard gate
        // to have passed. A timeout with no gates declared should still fail.
        $adapter = $this->fakeAdapter('fake', fn (): AiResponse => new AiResponse(
            success: false,
            output: '',
            error: 'Timeout after 1s',
            exitCode: 124,
        ));

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'body', adapterHint: 'fake'),
        ]);

        $result = $executor->execute($plan, $this->tmpDir, new RenderContext);

        $this->assertFalse($result->success);
        $this->assertCount(1, $result->failedSteps());
    }

    #[Test]
    public function fail_loud_when_template_references_unknown_var(): void
    {
        $adapter = $this->fakeAdapter('fake', fn () => new AiResponse(true, 'ok'));
        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'Hello {{nonexistent}}', adapterHint: 'fake'),
        ]);

        $result = $executor->execute($plan, $this->tmpDir, new RenderContext);

        $this->assertFalse($result->success);
        $failedSteps = $result->failedSteps();
        $this->assertNotEmpty($failedSteps);
        $this->assertStringContainsString('nonexistent', $failedSteps[0]->errorMessage);
    }

    #[Test]
    public function adapter_exception_is_converted_into_step_and_build_failure(): void
    {
        $adapter = $this->fakeAdapter('fake', function (): AiResponse {
            throw new \RuntimeException('adapter exploded');
        });

        $executor = $this->makeExecutor($adapter);
        $plan = (new PlanCompiler)->compile('test', [
            PlanStep::build('a', 'A', Complexity::SIMPLE, 'body', adapterHint: 'fake'),
        ]);

        $result = $executor->execute($plan, $this->tmpDir, new RenderContext);

        $this->assertFalse($result->success);
        $this->assertCount(1, $result->failedSteps());
        $this->assertStringContainsString('adapter exploded', $result->failedSteps()[0]->errorMessage);

        $events = $this->readEvents();
        $types = array_map(fn ($e) => $e['type'], $events);

        $this->assertContains(EventType::StepFail->value, $types);
        $this->assertContains(EventType::BuildFail->value, $types);
    }

    private function makeExecutor(AdapterInterface $adapter): PlanExecutor
    {
        $registry = new AdapterRegistry([$adapter]);
        $log = new EventLog($this->logPath, 'test-trace');

        return new PlanExecutor(adapters: $registry, eventLog: $log);
    }

    private function fakeAdapter(string $name, \Closure $execute): AdapterInterface
    {
        return new class($name, $execute) implements AdapterInterface
        {
            public function __construct(private string $n, private \Closure $exec) {}

            public function name(): string
            {
                return $this->n;
            }

            public function version(): ?string
            {
                return 'fake';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function supportsModel(?string $model): bool
            {
                return true;
            }

            public function execute(string $prompt, AdapterContext $context): AiResponse
            {
                return ($this->exec)($prompt);
            }

            public function estimateCost(int $estimatedInputTokens, ?int $estimatedOutputTokens = null): ?float
            {
                return null;
            }
        };
    }

    private function readEvents(): array
    {
        return (new EventLog($this->logPath, 'test-trace'))->readAll();
    }
}
