<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\ToolPreference;

final class ToolPreferenceTest extends TestCase
{
    #[Test]
    public function default_order_is_claude_gemini_codex(): void
    {
        $pref = new ToolPreference();

        $this->assertSame(
            ['claude', 'gemini', 'codex'],
            $pref->orderedTools(['claude', 'gemini', 'codex']),
        );
    }

    #[Test]
    public function custom_order_is_respected(): void
    {
        $pref = new ToolPreference(order: ['gemini', 'claude']);

        $ordered = $pref->orderedTools(['claude', 'gemini', 'codex']);
        $this->assertSame('gemini', $ordered[0]);
        $this->assertSame('claude', $ordered[1]);
    }

    #[Test]
    public function excluded_tools_are_filtered(): void
    {
        $pref = new ToolPreference(excluded: ['codex']);

        $ordered = $pref->orderedTools(['claude', 'gemini', 'codex']);
        $this->assertNotContains('codex', $ordered);
        $this->assertCount(2, $ordered);
    }

    #[Test]
    public function tier_for_claude_max_is_unlimited(): void
    {
        $pref = new ToolPreference(plans: ['claude' => 'max']);

        $this->assertSame('unlimited', $pref->tierFor('claude'));
    }

    #[Test]
    public function tier_for_claude_pro_is_generous(): void
    {
        $pref = new ToolPreference(plans: ['claude' => 'pro']);

        $this->assertSame('generous', $pref->tierFor('claude'));
    }

    #[Test]
    public function tier_for_codex_plus_is_generous(): void
    {
        $pref = new ToolPreference(plans: ['codex' => 'plus']);

        $this->assertSame('generous', $pref->tierFor('codex'));
    }

    #[Test]
    public function tier_for_free_plan_is_limited(): void
    {
        $pref = new ToolPreference(plans: ['gemini' => 'free']);

        $this->assertSame('limited', $pref->tierFor('gemini'));
    }

    #[Test]
    public function tier_for_unknown_plan_defaults_to_limited(): void
    {
        $pref = new ToolPreference(plans: ['claude' => 'enterprise-xyz']);

        $this->assertSame('limited', $pref->tierFor('claude'));
    }

    #[Test]
    public function tier_for_tool_without_plan_defaults_to_limited(): void
    {
        $pref = new ToolPreference();

        $this->assertSame('limited', $pref->tierFor('claude'));
    }

    #[Test]
    public function is_unlimited_only_true_for_max(): void
    {
        $pref = new ToolPreference(plans: [
            'claude' => 'max',
            'gemini' => 'free',
            'codex' => 'plus',
        ]);

        $this->assertTrue($pref->isUnlimited('claude'));
        $this->assertFalse($pref->isUnlimited('gemini'));
        $this->assertFalse($pref->isUnlimited('codex'));
    }

    #[Test]
    public function plans_derive_order_when_no_explicit_order(): void
    {
        $pref = new ToolPreference(plans: [
            'claude' => 'max',
            'codex' => 'plus',
            'gemini' => 'free',
        ]);

        $ordered = $pref->orderedTools(['claude', 'gemini', 'codex']);
        // max (unlimited) first, then plus (generous), then free (limited)
        $this->assertSame('claude', $ordered[0]);
        $this->assertSame('codex', $ordered[1]);
        $this->assertSame('gemini', $ordered[2]);
    }

    #[Test]
    public function ordered_tools_includes_unlisted_available_tools(): void
    {
        $pref = new ToolPreference(order: ['claude']);

        $ordered = $pref->orderedTools(['claude', 'gemini', 'codex']);
        $this->assertSame('claude', $ordered[0]);
        $this->assertContains('gemini', $ordered);
        $this->assertContains('codex', $ordered);
        $this->assertCount(3, $ordered);
    }

    #[Test]
    public function from_env_reads_environment_variables(): void
    {
        putenv('TESSERA_TOOL_PREFERENCE=gemini,claude');
        putenv('TESSERA_TOOL_EXCLUDE=codex');
        putenv('TESSERA_CLAUDE_PLAN=max');

        try {
            $pref = ToolPreference::fromEnv();

            $ordered = $pref->orderedTools(['claude', 'gemini', 'codex']);
            $this->assertSame('gemini', $ordered[0]);
            $this->assertNotContains('codex', $ordered);
            $this->assertTrue($pref->isUnlimited('claude'));
        } finally {
            putenv('TESSERA_TOOL_PREFERENCE');
            putenv('TESSERA_TOOL_EXCLUDE');
            putenv('TESSERA_CLAUDE_PLAN');
        }
    }

    #[Test]
    public function describe_returns_human_readable(): void
    {
        $pref = new ToolPreference(
            order: ['gemini', 'claude'],
            excluded: ['codex'],
            plans: ['claude' => 'max'],
        );

        $desc = $pref->describe();
        $this->assertStringContainsString('gemini', $desc);
        $this->assertStringContainsString('codex', $desc);
        $this->assertStringContainsString('claude=max', $desc);
    }

    #[Test]
    public function describe_returns_default_when_no_customization(): void
    {
        $pref = new ToolPreference();

        $this->assertSame('default', $pref->describe());
    }

    #[Test]
    public function plans_getter_returns_stored_plans(): void
    {
        $plans = ['claude' => 'max', 'gemini' => 'free'];
        $pref = new ToolPreference(plans: $plans);

        $this->assertSame($plans, $pref->plans());
    }
}
