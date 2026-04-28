<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Events\EventLog;
use Tessera\Installer\Events\EventType;
use Tessera\Installer\Schema\SchemaVersion;

final class EventLogTest extends TestCase
{
    private string $tmpDir;

    private string $logPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera-event-log-'.bin2hex(random_bytes(4));
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
    public function trace_id_is_assigned_when_not_provided(): void
    {
        $log = new EventLog($this->logPath);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $log->traceId());
    }

    #[Test]
    public function trace_id_uses_explicit_value_when_provided(): void
    {
        $log = new EventLog($this->logPath, 'fixed-trace');

        $this->assertSame('fixed-trace', $log->traceId());
    }

    #[Test]
    public function emit_appends_one_jsonl_line_per_event(): void
    {
        $log = new EventLog($this->logPath, 'tx-1');

        $log->emit(EventType::BuildStart, ['version' => '4.0']);
        $log->emit(EventType::StepStart, ['name' => 'models']);

        $this->assertSame(2, $log->count());
    }

    #[Test]
    public function emitted_lines_round_trip_through_read_all(): void
    {
        $log = new EventLog($this->logPath, 'tx-2');

        $log->emit(EventType::AiCallStart, ['adapter' => 'claude']);
        $log->emit(EventType::AiCallComplete, ['adapter' => 'claude', 'success' => true]);

        $events = $log->readAll();

        $this->assertCount(2, $events);
        $this->assertSame(SchemaVersion::EVENT_LOG_ENTRY, $events[0]['schema']);
        $this->assertSame('ai.call.start', $events[0]['type']);
        $this->assertSame('tx-2', $events[0]['trace_id']);
        $this->assertSame('ai.call.complete', $events[1]['type']);
        $this->assertTrue($events[1]['payload']['success']);
    }

    #[Test]
    public function emit_with_explicit_trace_id_overrides_default(): void
    {
        $log = new EventLog($this->logPath, 'default-trace');

        $log->emit(EventType::StepStart, [], 'child-trace');

        $events = $log->readAll();
        $this->assertSame('child-trace', $events[0]['trace_id']);
    }

    #[Test]
    public function silent_mode_does_not_write(): void
    {
        $log = new EventLog($this->logPath, 'tx-3', silent: true);

        $log->emit(EventType::BuildStart);
        $log->emit(EventType::BuildComplete);

        $this->assertSame(0, $log->count());
        $this->assertFileDoesNotExist($this->logPath);
    }

    #[Test]
    public function read_all_on_missing_file_returns_empty(): void
    {
        $log = new EventLog($this->logPath);

        $this->assertSame([], $log->readAll());
        $this->assertSame(0, $log->count());
    }

    #[Test]
    public function read_all_skips_malformed_lines(): void
    {
        $log = new EventLog($this->logPath, 'tx-4');
        $log->emit(EventType::BuildStart);

        // Inject a corrupt line — simulating concurrent-writer interleaving
        file_put_contents($this->logPath, "not-valid-json\n", FILE_APPEND);

        $log->emit(EventType::BuildComplete);

        $events = $log->readAll();

        $this->assertCount(2, $events);
        $this->assertSame('build.start', $events[0]['type']);
        $this->assertSame('build.complete', $events[1]['type']);
    }

    #[Test]
    public function path_returns_constructor_value(): void
    {
        $log = new EventLog($this->logPath);

        $this->assertSame($this->logPath, $log->path());
    }
}
