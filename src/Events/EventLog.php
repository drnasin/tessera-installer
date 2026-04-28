<?php

declare(strict_types=1);

namespace Tessera\Installer\Events;

/**
 * Append-only event sink writing JSON-Lines to `.tessera/events.jsonl`.
 *
 * Replaces "spinner theatre" with a structured trace consumers (humans,
 * `tessera analyze`, CI dashboards) can post-mortem without re-running.
 *
 * Concurrency: each emit() opens for append, takes an LOCK_EX on the file
 * handle, writes one full line, then releases. On Windows where exclusive
 * locks are advisory, the file open mode `a` already serialises writes at
 * the kernel level for reasonable record sizes. Worst case: two processes
 * write interleaved bytes and one record is unparseable — the surrounding
 * lines stay valid (JSONL is line-recoverable).
 *
 * The log holds a single trace_id for the lifetime of one build. Sub-steps
 * may stamp their own trace_id via Event::now(); the log's stored trace_id
 * is the build-level default when the caller doesn't supply one.
 */
final class EventLog
{
    private string $path;

    private string $traceId;

    private bool $silent;

    public function __construct(string $path, ?string $traceId = null, bool $silent = false)
    {
        $this->path = $path;
        $this->traceId = $traceId ?? bin2hex(random_bytes(8));
        $this->silent = $silent;

        $this->ensureDirectory();
    }

    /**
     * Build-level trace id assigned at construction; used when emit() is
     * called without an explicit override.
     */
    public function traceId(): string
    {
        return $this->traceId;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Append a single event line. The optional $traceId override lets a
     * step or AI call attach a child trace without mutating the log's
     * default. Silent mode (constructor flag) is a no-op — used for tests
     * where the event log wiring should run but writes should not touch
     * disk. See EventLogTest::silentModeDoesNotWrite().
     */
    public function emit(EventType $type, array $payload = [], ?string $traceId = null): void
    {
        if ($this->silent) {
            return;
        }

        $event = Event::now($type, $traceId ?? $this->traceId, $payload);

        $line = json_encode($event->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return;
        }

        $handle = @fopen($this->path, 'a');

        if ($handle === false) {
            return;
        }

        try {
            $locked = @flock($handle, LOCK_EX);
            @fwrite($handle, $line."\n");
            @fflush($handle);

            if ($locked) {
                @flock($handle, LOCK_UN);
            }
        } finally {
            @fclose($handle);
        }
    }

    /**
     * Read every event in the log as parsed arrays. Lines that fail to
     * decode are skipped (concurrent-writer interleavings). Useful for
     * `tessera analyze`, post-mortems, and tests.
     *
     * @return list<array<string, mixed>>
     */
    public function readAll(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $handle = @fopen($this->path, 'r');

        if ($handle === false) {
            return [];
        }

        $events = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (is_array($decoded)) {
                    $events[] = $decoded;
                }
            }
        } finally {
            @fclose($handle);
        }

        return $events;
    }

    /**
     * Count of events in the log. Cheap line count without parsing.
     */
    public function count(): int
    {
        if (! is_file($this->path)) {
            return 0;
        }

        $count = 0;
        $handle = @fopen($this->path, 'r');

        if ($handle === false) {
            return 0;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $count++;
                }
            }
        } finally {
            @fclose($handle);
        }

        return $count;
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);

        if ($dir === '' || $dir === '.') {
            return;
        }

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}
