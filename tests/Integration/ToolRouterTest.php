<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiTool;
use Tessera\Installer\Complexity;
use Tessera\Installer\ToolPreference;
use Tessera\Installer\ToolRouter;

final class ToolRouterTest extends TestCase
{
    private function makeRouter(
        array $toolNames = ['claude', 'gemini', 'codex'],
        ?ToolPreference $preference = null,
    ): ToolRouter {
        $tools = [];
        foreach ($toolNames as $name) {
            $tools[$name] = AiTool::fake($name);
        }

        return new ToolRouter($tools, $preference);
    }

    #[Test]
    public function resolve_simple_defaults_to_claude_haiku(): void
    {
        $router = $this->makeRouter();
        $selection = $router->resolve(Complexity::SIMPLE);

        $this->assertNotNull($selection);
        // Default preference: claude > gemini, so claude haiku is first for simple
        $this->assertSame('claude', $selection->tool->name());
        $this->assertStringContainsString('haiku', $selection->model);
    }

    #[Test]
    public function resolve_simple_with_gemini_preferred_uses_flash(): void
    {
        $pref = new ToolPreference(order: ['gemini', 'claude', 'codex']);
        $router = $this->makeRouter(['claude', 'gemini', 'codex'], $pref);
        $selection = $router->resolve(Complexity::SIMPLE);

        $this->assertNotNull($selection);
        $this->assertSame('gemini', $selection->tool->name());
        $this->assertStringContainsString('flash', $selection->model);
    }

    #[Test]
    public function resolve_medium_defaults_to_claude_sonnet(): void
    {
        $router = $this->makeRouter();
        $selection = $router->resolve(Complexity::MEDIUM);

        $this->assertNotNull($selection);
        $this->assertSame('claude', $selection->tool->name());
        $this->assertStringContainsString('sonnet', $selection->model);
    }

    #[Test]
    public function resolve_complex_defaults_to_claude_opus(): void
    {
        $router = $this->makeRouter();
        $selection = $router->resolve(Complexity::COMPLEX);

        $this->assertNotNull($selection);
        $this->assertSame('claude', $selection->tool->name());
        $this->assertStringContainsString('opus', $selection->model);
    }

    #[Test]
    public function resolve_with_only_gemini_uses_gemini_for_all(): void
    {
        $router = $this->makeRouter(['gemini']);

        foreach (Complexity::cases() as $complexity) {
            $selection = $router->resolve($complexity);
            $this->assertNotNull($selection);
            $this->assertSame('gemini', $selection->tool->name());
        }
    }

    #[Test]
    public function resolve_with_only_codex_uses_null_model(): void
    {
        $router = $this->makeRouter(['codex']);
        $selection = $router->resolve(Complexity::COMPLEX);

        $this->assertNotNull($selection);
        $this->assertSame('codex', $selection->tool->name());
        $this->assertNull($selection->model);
    }

    #[Test]
    public function resolve_skips_rate_limited_tools(): void
    {
        $router = $this->makeRouter(['claude', 'gemini']);
        $router->rateLimits()->markRateLimited('claude');

        $selection = $router->resolve(Complexity::COMPLEX);
        $this->assertNotNull($selection);
        $this->assertSame('gemini', $selection->tool->name());
    }

    #[Test]
    public function resolve_skips_dead_tools(): void
    {
        $router = $this->makeRouter(['claude', 'gemini']);
        $router->rateLimits()->markDead('claude');

        $selection = $router->resolve(Complexity::COMPLEX);
        $this->assertNotNull($selection);
        $this->assertSame('gemini', $selection->tool->name());
    }

    #[Test]
    public function resolve_returns_null_when_all_unavailable(): void
    {
        $router = $this->makeRouter(['claude']);
        $router->rateLimits()->markDead('claude');

        $this->assertNull($router->resolve(Complexity::COMPLEX));
    }

    #[Test]
    public function unlimited_plan_preferred_for_all_complexities(): void
    {
        $pref = new ToolPreference(plans: ['claude' => 'max', 'gemini' => 'free']);
        $router = $this->makeRouter(['claude', 'gemini'], $pref);

        // Even for SIMPLE, claude should be first because it's unlimited
        $selection = $router->resolve(Complexity::SIMPLE);
        $this->assertNotNull($selection);
        $this->assertSame('claude', $selection->tool->name());
    }

    #[Test]
    public function preference_order_affects_resolution(): void
    {
        $pref = new ToolPreference(order: ['gemini', 'claude']);
        $router = $this->makeRouter(['claude', 'gemini'], $pref);

        // Gemini preferred, so for SIMPLE it should still pick gemini
        $selection = $router->resolve(Complexity::SIMPLE);
        $this->assertNotNull($selection);
        $this->assertSame('gemini', $selection->tool->name());
    }

    #[Test]
    public function excluded_tool_is_never_resolved(): void
    {
        $pref = new ToolPreference(excluded: ['gemini']);
        $router = $this->makeRouter(['claude', 'gemini', 'codex'], $pref);

        foreach (Complexity::cases() as $complexity) {
            $selection = $router->resolve($complexity);
            $this->assertNotNull($selection);
            $this->assertNotSame('gemini', $selection->tool->name());
        }
    }

    #[Test]
    public function primary_returns_first_available_tool(): void
    {
        $router = $this->makeRouter(['claude', 'gemini']);

        $this->assertSame('claude', $router->primary()->name());
    }

    #[Test]
    public function primary_falls_back_on_rate_limit(): void
    {
        $router = $this->makeRouter(['claude', 'gemini']);
        $router->rateLimits()->markRateLimited('claude');

        $this->assertSame('gemini', $router->primary()->name());
    }

    #[Test]
    public function primary_returns_something_even_if_all_limited(): void
    {
        $router = $this->makeRouter(['claude', 'gemini']);
        $router->rateLimits()->markRateLimited('claude');
        $router->rateLimits()->markRateLimited('gemini');

        // Should return first tool anyway (caller handles failure)
        $tool = $router->primary();
        $this->assertNotNull($tool);
    }

    #[Test]
    public function describe_shows_routing_for_all_complexities(): void
    {
        $router = $this->makeRouter();
        $desc = $router->describe();

        $this->assertStringContainsString('simple:', $desc);
        $this->assertStringContainsString('medium:', $desc);
        $this->assertStringContainsString('complex:', $desc);
    }

    #[Test]
    public function describe_shows_plans_when_configured(): void
    {
        $pref = new ToolPreference(plans: ['claude' => 'max']);
        $router = $this->makeRouter(['claude'], $pref);

        $desc = $router->describe();
        $this->assertStringContainsString('plans:', $desc);
        $this->assertStringContainsString('claude=max', $desc);
    }

    #[Test]
    public function available_names_returns_tool_keys(): void
    {
        $router = $this->makeRouter(['claude', 'gemini']);

        $this->assertSame(['claude', 'gemini'], $router->availableNames());
    }

    #[Test]
    public function count_returns_tool_count(): void
    {
        $router = $this->makeRouter(['claude', 'gemini', 'codex']);

        $this->assertSame(3, $router->count());
    }

    #[Test]
    public function with_single_tool_factory(): void
    {
        $router = ToolRouter::withSingleTool(AiTool::fake('claude'));

        $this->assertSame(1, $router->count());
        $this->assertSame('claude', $router->primary()->name());
    }

    #[Test]
    public function usage_tracker_is_accessible(): void
    {
        $router = $this->makeRouter();

        $this->assertSame(0, $router->usage()->totalCalls());
    }

    // --- Reviewer tests ---

    #[Test]
    public function reviewer_uses_different_tool_when_multiple_available(): void
    {
        $router = $this->makeRouter(['claude', 'gemini']);
        $reviewer = $router->resolveReviewer(Complexity::COMPLEX);

        $this->assertNotNull($reviewer);
        // Primary for COMPLEX is claude, so reviewer should be gemini
        $primary = $router->resolve(Complexity::COMPLEX);
        $this->assertNotSame($primary->tool->name(), $reviewer->tool->name());
    }

    #[Test]
    public function reviewer_uses_cheapest_model_of_other_tool(): void
    {
        $router = $this->makeRouter(['claude', 'gemini']);
        $reviewer = $router->resolveReviewer(Complexity::COMPLEX);

        $this->assertNotNull($reviewer);
        // Should use the simple (cheapest) model of the other tool
        if ($reviewer->tool->name() === 'gemini') {
            $this->assertStringContainsString('flash', $reviewer->model);
        }
    }

    #[Test]
    public function reviewer_uses_lighter_model_when_single_tool(): void
    {
        $router = $this->makeRouter(['claude']);
        $reviewer = $router->resolveReviewer(Complexity::COMPLEX);

        $this->assertNotNull($reviewer);
        // Same tool, but lighter model (opus → haiku)
        $this->assertSame('claude', $reviewer->tool->name());
        $this->assertStringContainsString('haiku', $reviewer->model);
    }

    #[Test]
    public function reviewer_returns_null_for_codex_only(): void
    {
        $router = $this->makeRouter(['codex']);
        // Codex has no model selection — can't provide a different perspective
        $reviewer = $router->resolveReviewer(Complexity::COMPLEX);

        $this->assertNull($reviewer);
    }

    #[Test]
    public function reviewer_skips_rate_limited_tools(): void
    {
        $router = $this->makeRouter(['claude', 'gemini', 'codex']);
        $router->rateLimits()->markRateLimited('gemini');

        $reviewer = $router->resolveReviewer(Complexity::COMPLEX);
        $this->assertNotNull($reviewer);
        // Gemini is rate-limited, so reviewer should be codex (next available different tool)
        $this->assertNotSame('claude', $reviewer->tool->name());
        $this->assertNotSame('gemini', $reviewer->tool->name());
    }

    #[Test]
    public function reviewer_falls_back_to_lighter_model_when_all_others_unavailable(): void
    {
        $router = $this->makeRouter(['claude', 'gemini']);
        $router->rateLimits()->markDead('gemini');

        $reviewer = $router->resolveReviewer(Complexity::COMPLEX);
        $this->assertNotNull($reviewer);
        // Gemini dead, so falls back to claude with lighter model
        $this->assertSame('claude', $reviewer->tool->name());
        $this->assertStringContainsString('haiku', $reviewer->model);
    }
}
