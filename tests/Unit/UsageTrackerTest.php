<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\UsageTracker;

final class UsageTrackerTest extends TestCase
{
    #[Test]
    public function initial_state_has_zero_calls(): void
    {
        $tracker = new UsageTracker();

        $this->assertSame(0, $tracker->totalCalls());
        $this->assertSame([], $tracker->toArray());
    }

    #[Test]
    public function record_increments_count(): void
    {
        $tracker = new UsageTracker();
        $tracker->record('claude', 'claude-opus-4-20250514');

        $this->assertSame(1, $tracker->totalCalls());
    }

    #[Test]
    public function record_multiple_tools_and_models(): void
    {
        $tracker = new UsageTracker();
        $tracker->record('claude', 'claude-opus-4-20250514');
        $tracker->record('claude', 'claude-opus-4-20250514');
        $tracker->record('claude', 'claude-sonnet-4-20250514');
        $tracker->record('gemini', 'gemini-2.0-flash');

        $this->assertSame(4, $tracker->totalCalls());

        $data = $tracker->toArray();
        $this->assertSame(2, $data['claude']['claude-opus-4-20250514']);
        $this->assertSame(1, $data['claude']['claude-sonnet-4-20250514']);
        $this->assertSame(1, $data['gemini']['gemini-2.0-flash']);
    }

    #[Test]
    public function null_model_records_as_default(): void
    {
        $tracker = new UsageTracker();
        $tracker->record('codex', null);

        $data = $tracker->toArray();
        $this->assertSame(1, $data['codex']['default']);
    }

    #[Test]
    public function summary_formats_correctly(): void
    {
        $tracker = new UsageTracker();
        $tracker->record('claude', 'claude-opus-4-20250514');
        $tracker->record('claude', 'claude-sonnet-4-20250514');
        $tracker->record('gemini', 'gemini-2.0-flash');

        $summary = $tracker->summary();
        $this->assertStringContainsString('claude: 2 calls', $summary);
        $this->assertStringContainsString('gemini: 1 calls', $summary);
    }

    #[Test]
    public function summary_shortens_model_names(): void
    {
        $tracker = new UsageTracker();
        $tracker->record('claude', 'claude-opus-4-20250514');
        $tracker->record('gemini', 'gemini-2.0-flash');

        $summary = $tracker->summary();
        $this->assertStringContainsString('opus', $summary);
        $this->assertStringContainsString('flash', $summary);
        $this->assertStringNotContainsString('20250514', $summary);
    }

    #[Test]
    public function summary_with_no_calls_returns_message(): void
    {
        $tracker = new UsageTracker();

        $this->assertSame('No AI calls made.', $tracker->summary());
    }

    #[Test]
    public function to_array_returns_nested_structure(): void
    {
        $tracker = new UsageTracker();
        $tracker->record('claude', 'model-a');
        $tracker->record('claude', 'model-b');

        $data = $tracker->toArray();
        $this->assertArrayHasKey('claude', $data);
        $this->assertArrayHasKey('model-a', $data['claude']);
        $this->assertArrayHasKey('model-b', $data['claude']);
    }
}
