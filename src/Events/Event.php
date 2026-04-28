<?php

declare(strict_types=1);

namespace Tessera\Installer\Events;

use Tessera\Installer\Schema\SchemaVersion;

/**
 * A single immutable event in the build trace.
 *
 * Serialized as one line of `.tessera/events.jsonl`. Order in the file is
 * insertion order; consumers MUST NOT assume the file is sorted by
 * timestamp (concurrent writers exist on Windows).
 */
final readonly class Event
{
    public function __construct(
        public EventType $type,
        public string $traceId,
        public string $occurredAt,
        public array $payload,
    ) {}

    public static function now(EventType $type, string $traceId, array $payload = []): self
    {
        return new self($type, $traceId, gmdate('Y-m-d\TH:i:s\Z'), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => SchemaVersion::EVENT_LOG_ENTRY,
            'type' => $this->type->value,
            'trace_id' => $this->traceId,
            'occurred_at' => $this->occurredAt,
            'payload' => $this->payload,
        ];
    }
}
