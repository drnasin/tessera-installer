<?php

declare(strict_types=1);

namespace Tessera\Installer;

final readonly class AiResponse
{
    public function __construct(
        public bool $success,
        public string $output,
        public string $error = '',
        public int $exitCode = 0,
    ) {}

    /**
     * Detect if the failure was due to rate limiting.
     */
    public function isRateLimited(): bool
    {
        if ($this->success) {
            return false;
        }

        $combined = strtolower($this->error.' '.$this->output);

        $patterns = [
            'rate limit',
            'rate_limit',
            'ratelimit',
            'rate limited',
            'too many requests',
            '429',
            'overloaded',
            'overloaded_error',
            'capacity',
            'quota exceeded',
            'quota_exceeded',
            'resource_exhausted',
            'try again later',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($combined, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect if the tool is completely down (not just rate limited).
     */
    public function isToolDown(): bool
    {
        if ($this->success) {
            return false;
        }

        // Command not found
        if ($this->exitCode === 127) {
            return true;
        }

        $combined = strtolower($this->error);

        $patterns = [
            'connection refused',
            'network error',
            'service unavailable',
            '503',
            'could not connect',
            'failed to start ai process',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($combined, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
