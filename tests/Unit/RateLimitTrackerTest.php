<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\RateLimitTracker;

final class RateLimitTrackerTest extends TestCase
{
    #[Test]
    public function new_tool_is_available(): void
    {
        $tracker = new RateLimitTracker();

        $this->assertTrue($tracker->isAvailable('claude'));
    }

    #[Test]
    public function rate_limited_tool_is_unavailable(): void
    {
        $tracker = new RateLimitTracker();
        $tracker->markRateLimited('claude');

        $this->assertFalse($tracker->isAvailable('claude'));
    }

    #[Test]
    public function rate_limited_tool_becomes_available_after_cooldown(): void
    {
        $tracker = new RateLimitTracker(cooldownSeconds: 1);
        $tracker->markRateLimited('claude');

        $this->assertFalse($tracker->isAvailable('claude'));

        sleep(2);

        $this->assertTrue($tracker->isAvailable('claude'));
    }

    #[Test]
    public function dead_tool_is_never_available(): void
    {
        $tracker = new RateLimitTracker(cooldownSeconds: 1);
        $tracker->markDead('claude');

        $this->assertFalse($tracker->isAvailable('claude'));

        sleep(2);

        $this->assertFalse($tracker->isAvailable('claude'));
    }

    #[Test]
    public function cooldown_remaining_for_never_limited_tool_is_zero(): void
    {
        $tracker = new RateLimitTracker();

        $this->assertSame(0, $tracker->cooldownRemaining('claude'));
    }

    #[Test]
    public function cooldown_remaining_returns_positive_value(): void
    {
        $tracker = new RateLimitTracker(cooldownSeconds: 60);
        $tracker->markRateLimited('claude');

        $remaining = $tracker->cooldownRemaining('claude');
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(60, $remaining);
    }

    #[Test]
    public function mark_dead_is_idempotent(): void
    {
        $tracker = new RateLimitTracker();
        $tracker->markDead('claude');
        $tracker->markDead('claude');

        $this->assertFalse($tracker->isAvailable('claude'));
    }

    #[Test]
    public function has_any_available_with_mixed_state(): void
    {
        $tracker = new RateLimitTracker();
        $tracker->markDead('claude');

        $this->assertTrue($tracker->hasAnyAvailable(['claude', 'gemini']));
    }

    #[Test]
    public function has_any_available_returns_false_when_all_unavailable(): void
    {
        $tracker = new RateLimitTracker();
        $tracker->markDead('claude');
        $tracker->markDead('gemini');

        $this->assertFalse($tracker->hasAnyAvailable(['claude', 'gemini']));
    }

    #[Test]
    public function other_tools_unaffected_by_rate_limit(): void
    {
        $tracker = new RateLimitTracker();
        $tracker->markRateLimited('claude');

        $this->assertFalse($tracker->isAvailable('claude'));
        $this->assertTrue($tracker->isAvailable('gemini'));
        $this->assertTrue($tracker->isAvailable('codex'));
    }
}
