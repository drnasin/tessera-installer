<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Events\Event;
use Tessera\Installer\Events\EventType;
use Tessera\Installer\Schema\SchemaVersion;

final class EventTest extends TestCase
{
    #[Test]
    public function to_array_includes_schema_field(): void
    {
        $event = new Event(EventType::StepStart, 'trace-123', '2026-04-27T12:00:00Z', ['step' => 'models']);

        $arr = $event->toArray();

        $this->assertSame(SchemaVersion::EVENT_LOG_ENTRY, $arr['schema']);
        $this->assertSame('step.start', $arr['type']);
        $this->assertSame('trace-123', $arr['trace_id']);
        $this->assertSame('2026-04-27T12:00:00Z', $arr['occurred_at']);
        $this->assertSame(['step' => 'models'], $arr['payload']);
    }

    #[Test]
    public function now_uses_iso8601_utc_format(): void
    {
        $event = Event::now(EventType::AiCallStart, 'trace-xyz', []);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $event->occurredAt,
        );
    }

    #[Test]
    public function now_assigns_provided_trace_id(): void
    {
        $event = Event::now(EventType::BuildStart, 'tx-abc', ['k' => 'v']);

        $this->assertSame('tx-abc', $event->traceId);
        $this->assertSame(['k' => 'v'], $event->payload);
    }
}
