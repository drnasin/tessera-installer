<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Events\EventLog;
use Tessera\Installer\Plan\GateEvaluator;
use Tessera\Installer\Plan\GateResult;

final class GateEvaluatorTest extends TestCase
{
    private string $workingDir;

    protected function setUp(): void
    {
        $this->workingDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera-gate-'.bin2hex(random_bytes(4));
        mkdir($this->workingDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workingDir)) {
            foreach (glob($this->workingDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->workingDir);
        }
    }

    #[Test]
    public function exists_any_passes_when_first_pattern_matches(): void
    {
        touch($this->workingDir.'/index.html');

        $results = (new GateEvaluator)->evaluate('scaffold', [
            ['type' => 'exists_any', 'patterns' => ['index.html', 'package.json']],
        ], $this->workingDir);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->passed);
        $this->assertSame('exists_any', $results[0]->gateType);
    }

    #[Test]
    public function exists_any_passes_when_later_pattern_matches(): void
    {
        touch($this->workingDir.'/package.json');

        $results = (new GateEvaluator)->evaluate('scaffold', [
            ['type' => 'exists_any', 'patterns' => ['index.html', 'package.json']],
        ], $this->workingDir);

        $this->assertTrue($results[0]->passed);
    }

    #[Test]
    public function exists_any_fails_when_no_pattern_matches(): void
    {
        $results = (new GateEvaluator)->evaluate('scaffold', [
            ['type' => 'exists_any', 'patterns' => ['index.html', 'package.json']],
        ], $this->workingDir);

        $this->assertFalse($results[0]->passed);
        $this->assertStringContainsString('None of the expected paths exist', $results[0]->message);
    }

    #[Test]
    public function exists_all_passes_when_every_path_present(): void
    {
        touch($this->workingDir.'/a.txt');
        touch($this->workingDir.'/b.txt');

        $results = (new GateEvaluator)->evaluate('step', [
            ['type' => 'exists_all', 'paths' => ['a.txt', 'b.txt']],
        ], $this->workingDir);

        $this->assertTrue($results[0]->passed);
    }

    #[Test]
    public function exists_all_fails_with_missing_list(): void
    {
        touch($this->workingDir.'/a.txt');

        $results = (new GateEvaluator)->evaluate('step', [
            ['type' => 'exists_all', 'paths' => ['a.txt', 'b.txt', 'c.txt']],
        ], $this->workingDir);

        $this->assertFalse($results[0]->passed);
        $this->assertSame(['b.txt', 'c.txt'], $results[0]->detail['missing']);
    }

    #[Test]
    public function hard_failure_halts(): void
    {
        $result = GateResult::failed('s', 'exists_any', 'hard', 'missing');

        $this->assertTrue($result->halts());
    }

    #[Test]
    public function soft_failure_does_not_halt(): void
    {
        $result = GateResult::failed('s', 'exists_any', 'soft', 'missing');

        $this->assertFalse($result->halts());
    }

    #[Test]
    public function emits_pass_or_fail_event_per_gate(): void
    {
        $logPath = $this->workingDir.'/events.jsonl';
        $log = new EventLog($logPath, 'test-trace');
        touch($this->workingDir.'/index.html');

        (new GateEvaluator($log))->evaluate('scaffold', [
            ['type' => 'exists_any', 'patterns' => ['index.html']],
            ['type' => 'exists_all', 'paths' => ['nonexistent.html']],
        ], $this->workingDir);

        $events = $log->readAll();
        $types = array_map(fn ($e): string => $e['type'], $events);

        $this->assertContains('gate.pass', $types);
        $this->assertContains('gate.fail', $types);

        @unlink($logPath);
    }

    #[Test]
    public function unknown_gate_type_fails_loudly(): void
    {
        $results = (new GateEvaluator)->evaluate('s', [
            ['type' => 'mystery_meat'],
        ], $this->workingDir);

        $this->assertFalse($results[0]->passed);
        $this->assertStringContainsString('Unknown gate type', $results[0]->message);
    }

    #[Test]
    public function path_traversal_attempts_are_rejected(): void
    {
        $results = (new GateEvaluator)->evaluate('s', [
            ['type' => 'exists_any', 'patterns' => ['../../etc/passwd']],
        ], $this->workingDir);

        $this->assertFalse($results[0]->passed);
    }

    #[Test]
    public function defaults_to_hard_severity_when_unspecified(): void
    {
        $results = (new GateEvaluator)->evaluate('s', [
            ['type' => 'exists_any', 'patterns' => ['nope.html']],
        ], $this->workingDir);

        $this->assertSame('hard', $results[0]->severity);
        $this->assertTrue($results[0]->halts());
    }
}
