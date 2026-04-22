<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Result of a CommandRunner::run() invocation.
 *
 * `exitCode` uses convention: 0 = success, 124 = timeout, 127 = not found,
 * other values = command-specific failure.
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut,
        public float $durationSeconds,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Combined stdout+stderr with a separator — useful for legacy consumers
     * that used Console::execSilent's concatenated output.
     */
    public function combinedOutput(): string
    {
        $parts = [];
        if ($this->stdout !== '') {
            $parts[] = trim($this->stdout);
        }
        if ($this->stderr !== '') {
            $parts[] = trim($this->stderr);
        }

        return implode("\n", $parts);
    }
}
