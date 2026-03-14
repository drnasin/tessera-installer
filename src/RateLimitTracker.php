<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Tracks which AI tools are rate-limited or down.
 *
 * Rate-limited tools get a cooldown period before being retried.
 * Dead tools are skipped for the remainder of the build.
 */
final class RateLimitTracker
{
    /** @var array<string, int> tool name => timestamp when rate limit was hit */
    private array $rateLimitedAt = [];

    /** @var array<string> tools that are completely down */
    private array $deadTools = [];

    private int $cooldownSeconds;

    public function __construct(int $cooldownSeconds = 120)
    {
        $this->cooldownSeconds = $cooldownSeconds;
    }

    public function markRateLimited(string $toolName): void
    {
        $this->rateLimitedAt[$toolName] = time();
    }

    public function markDead(string $toolName): void
    {
        if (! in_array($toolName, $this->deadTools, true)) {
            $this->deadTools[] = $toolName;
        }
    }

    public function isAvailable(string $toolName): bool
    {
        if (in_array($toolName, $this->deadTools, true)) {
            return false;
        }

        if (! isset($this->rateLimitedAt[$toolName])) {
            return true;
        }

        $elapsed = time() - $this->rateLimitedAt[$toolName];

        if ($elapsed >= $this->cooldownSeconds) {
            unset($this->rateLimitedAt[$toolName]);

            return true;
        }

        return false;
    }

    public function cooldownRemaining(string $toolName): int
    {
        if (! isset($this->rateLimitedAt[$toolName])) {
            return 0;
        }

        return max(0, $this->cooldownSeconds - (time() - $this->rateLimitedAt[$toolName]));
    }

    /**
     * @param  array<string>  $toolNames
     */
    public function hasAnyAvailable(array $toolNames): bool
    {
        foreach ($toolNames as $name) {
            if ($this->isAvailable($name)) {
                return true;
            }
        }

        return false;
    }
}
