<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Smart cross-tool AI routing with rate limit awareness.
 *
 * Each task is routed to the best available tool+model for its complexity.
 * Rate-limited tools are automatically skipped with fallback to alternatives.
 * Usage is tracked per tool+model for summary display.
 *
 * Default (no plans set):
 *   SIMPLE  → Gemini Flash > Claude Haiku > Codex
 *   MEDIUM  → Claude Sonnet > Gemini Pro > Claude Haiku > Gemini Flash > Codex
 *   COMPLEX → Claude Opus > Gemini Pro > Claude Sonnet > Gemini Flash > Codex
 *
 * With TESSERA_CLAUDE_PLAN=max (unlimited):
 *   SIMPLE  → Claude Haiku > Gemini Flash > Codex
 *   MEDIUM  → Claude Sonnet > Gemini Pro > Codex
 *   COMPLEX → Claude Opus > Gemini Pro > Codex
 */
final class ToolRouter
{
    private const MODEL_MAP = [
        'claude' => [
            'simple' => 'claude-haiku-4-5-20251001',
            'medium' => 'claude-sonnet-4-20250514',
            'complex' => 'claude-opus-4-20250514',
        ],
        'gemini' => [
            'simple' => 'gemini-2.0-flash',
            'medium' => 'gemini-2.5-pro',
            'complex' => 'gemini-2.5-pro',
        ],
        'codex' => [
            'simple' => null,
            'medium' => null,
            'complex' => null,
        ],
    ];

    /**
     * Fallback chains per complexity — ordered by capability fit.
     * Each entry is [tool, model]. The chain is independent of user preference;
     * preference reorders WITHIN the chain but doesn't change the model mapping.
     *
     * @var array<string, array<int, array{0: string, 1: ?string}>>
     */
    private const FALLBACK_CHAINS = [
        'simple' => [
            ['gemini', 'gemini-2.0-flash'],
            ['claude', 'claude-haiku-4-5-20251001'],
            ['codex', null],
            ['claude', 'claude-sonnet-4-20250514'],
            ['gemini', 'gemini-2.5-pro'],
        ],
        'medium' => [
            ['claude', 'claude-sonnet-4-20250514'],
            ['gemini', 'gemini-2.5-pro'],
            ['claude', 'claude-haiku-4-5-20251001'],
            ['gemini', 'gemini-2.0-flash'],
            ['codex', null],
        ],
        'complex' => [
            ['claude', 'claude-opus-4-20250514'],
            ['gemini', 'gemini-2.5-pro'],
            ['claude', 'claude-sonnet-4-20250514'],
            ['gemini', 'gemini-2.0-flash'],
            ['codex', null],
        ],
    ];

    /** @var array<string, AiTool> */
    private array $tools;

    private ToolPreference $preference;

    private RateLimitTracker $rateLimits;

    private UsageTracker $usage;

    /**
     * @param  array<string, AiTool>  $tools  Available tools keyed by name
     */
    public function __construct(array $tools, ?ToolPreference $preference = null)
    {
        $this->tools = $tools;
        $this->preference = $preference ?? new ToolPreference;
        $this->rateLimits = new RateLimitTracker;
        $this->usage = new UsageTracker;
    }

    /**
     * Create a router from auto-detection of installed tools.
     */
    public static function detect(?ToolPreference $preference = null): ?self
    {
        $detected = AiTool::detectAllInstances();

        if (empty($detected)) {
            return null;
        }

        return new self($detected, $preference);
    }

    /**
     * Create a router with a single tool (backwards compatibility).
     */
    public static function withSingleTool(AiTool $tool): self
    {
        return new self([$tool->name() => $tool]);
    }

    /**
     * Resolve the best available tool+model for a complexity level.
     * Respects rate limits, dead tools, and user preferences.
     */
    public function resolve(Complexity $complexity): ?ToolSelection
    {
        $attempts = $this->buildAttemptOrder($complexity);

        foreach ($attempts as [$toolName, $model]) {
            if (! isset($this->tools[$toolName])) {
                continue;
            }

            if (! $this->rateLimits->isAvailable($toolName)) {
                continue;
            }

            return new ToolSelection($this->tools[$toolName], $model);
        }

        return null;
    }

    /**
     * Execute a prompt with automatic fallback on rate limits and failures.
     * This is the primary method for running AI tasks.
     */
    public function executeWithFallback(
        string $prompt,
        string $workingDir,
        Complexity $complexity,
        int $timeout = 300,
    ): AiResponse {
        $attempts = $this->buildAttemptOrder($complexity);
        $lastResponse = null;

        foreach ($attempts as [$toolName, $model]) {
            if (! isset($this->tools[$toolName])) {
                continue;
            }

            if (! $this->rateLimits->isAvailable($toolName)) {
                continue;
            }

            $tool = $this->tools[$toolName];
            $modelDisplay = $model !== null ? basename($model) : 'default';
            Console::line("  Using: {$toolName} ({$modelDisplay})");

            $this->usage->record($toolName, $model);
            $response = $tool->execute($prompt, $workingDir, $timeout, $model);
            $lastResponse = $response;

            if ($response->success) {
                return $response;
            }

            if ($response->isRateLimited()) {
                $this->rateLimits->markRateLimited($toolName);
                $cooldown = $this->rateLimits->cooldownRemaining($toolName);
                Console::warn("  {$toolName} rate-limited (cooldown: {$cooldown}s). Trying next tool...");

                continue;
            }

            if ($response->isToolDown()) {
                $this->rateLimits->markDead($toolName);
                Console::warn("  {$toolName} appears down. Skipping for remaining steps.");

                continue;
            }

            // Other failure — still try next in chain
            if ($response->error !== '') {
                Console::warn("  {$toolName} failed: {$response->error}");
            }

            continue;
        }

        return $lastResponse ?? new AiResponse(false, '', 'All AI tools unavailable', 1);
    }

    /**
     * Get the primary tool for conversational use (requirements gathering).
     * Rate-limit aware.
     */
    public function primary(): AiTool
    {
        $preferredOrder = $this->preference->orderedTools(array_keys($this->tools));

        foreach ($preferredOrder as $name) {
            if ($this->rateLimits->isAvailable($name) && isset($this->tools[$name])) {
                return $this->tools[$name];
            }
        }

        // Fallback: return first tool even if rate-limited (caller handles failure)
        return reset($this->tools);
    }

    public function usage(): UsageTracker
    {
        return $this->usage;
    }

    public function rateLimits(): RateLimitTracker
    {
        return $this->rateLimits;
    }

    /**
     * @return array<string>
     */
    public function availableNames(): array
    {
        return array_keys($this->tools);
    }

    public function count(): int
    {
        return count($this->tools);
    }

    /**
     * Build display string showing routing info per complexity.
     */
    public function describe(): string
    {
        $lines = [];

        // Show plans if configured
        $plans = $this->preference->plans();
        if (! empty($plans)) {
            $planParts = [];
            foreach ($plans as $tool => $plan) {
                $tier = $this->preference->tierFor($tool);
                $planParts[] = "{$tool}={$plan} ({$tier})";
            }
            $lines[] = '  plans: '.implode(', ', $planParts);
        }

        foreach ([Complexity::SIMPLE, Complexity::MEDIUM, Complexity::COMPLEX] as $complexity) {
            $selection = $this->resolve($complexity);

            if ($selection !== null) {
                $model = $selection->model !== null ? " ({$selection->model})" : '';
                $lines[] = "  {$complexity->value}: {$selection->tool->name()}{$model}";
            } else {
                $lines[] = "  {$complexity->value}: (no tool available)";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build ordered list of [toolName, model] attempts for a complexity.
     *
     * Plan-aware: unlimited tools are preferred for ALL complexity levels
     * (no reason to use Gemini Flash when Claude Max is unlimited).
     * User preference reorders tools within the chain.
     *
     * @return array<int, array{0: string, 1: ?string}>
     */
    private function buildAttemptOrder(Complexity $complexity): array
    {
        $chain = self::FALLBACK_CHAINS[$complexity->value];
        $preferredOrder = $this->preference->orderedTools(array_keys($this->tools));

        $attempts = [];
        $seen = [];

        // First pass: unlimited tools get their best model for this complexity first.
        // With Claude Max there's no reason to route simple tasks to Gemini Flash.
        foreach ($preferredOrder as $preferred) {
            if (! $this->preference->isUnlimited($preferred)) {
                continue;
            }

            // Add the complexity-appropriate model for this unlimited tool
            $model = self::MODEL_MAP[$preferred][$complexity->value] ?? null;
            $key = $preferred.':'.($model ?? 'null');

            if (! isset($seen[$key])) {
                $attempts[] = [$preferred, $model];
                $seen[$key] = true;
            }
        }

        // Second pass: add chain entries for preferred tools (in preference order)
        foreach ($preferredOrder as $preferred) {
            foreach ($chain as [$toolName, $model]) {
                if ($toolName !== $preferred) {
                    continue;
                }

                $key = $toolName.':'.($model ?? 'null');

                if (! isset($seen[$key])) {
                    $attempts[] = [$toolName, $model];
                    $seen[$key] = true;
                }
            }
        }

        // Third pass: add remaining chain entries not yet included
        foreach ($chain as [$toolName, $model]) {
            $key = $toolName.':'.($model ?? 'null');

            if (! isset($seen[$key])) {
                $attempts[] = [$toolName, $model];
                $seen[$key] = true;
            }
        }

        return $attempts;
    }
}
