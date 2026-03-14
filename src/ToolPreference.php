<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * User-configurable AI tool preferences with plan awareness.
 *
 * Environment variables:
 *   TESSERA_TOOL_PREFERENCE=gemini,claude,codex   (order)
 *   TESSERA_TOOL_EXCLUDE=codex                    (never use)
 *   TESSERA_CLAUDE_PLAN=max                       (max|pro|free)
 *   TESSERA_CODEX_PLAN=plus                       (plus|free)
 *   TESSERA_GEMINI_PLAN=free                      (pro|free)
 *
 * Plan tiers determine cost priority:
 *   unlimited (claude max)         → prefer for everything, no cost concern
 *   generous  (claude pro, codex plus) → prefer but watch for limits
 *   limited   (gemini free, codex free) → use as fallback only
 */
final class ToolPreference
{
    /** @var array<string> */
    private array $order;

    /** @var array<string> */
    private array $excluded;

    /** @var array<string, string> tool => plan name */
    private array $plans;

    /**
     * Plan tier mapping: plan name → cost tier.
     * Higher tier = more freely available.
     */
    private const PLAN_TIERS = [
        // Claude plans
        'max' => 'unlimited',
        'pro' => 'generous',
        // Codex/OpenAI plans
        'plus' => 'generous',
        // Gemini plans (pro = Google One AI Premium)
        // Generic
        'unlimited' => 'unlimited',
        'paid' => 'generous',
        'free' => 'limited',
    ];

    /**
     * Tier priority for sorting (higher = prefer more).
     */
    private const TIER_PRIORITY = [
        'unlimited' => 3,
        'generous' => 2,
        'limited' => 1,
    ];

    /**
     * @param  array<string>|null  $order  Custom preference order (null = auto from plans)
     * @param  array<string>  $excluded  Tools to exclude
     * @param  array<string, string>  $plans  Tool plans (e.g., ['claude' => 'max'])
     */
    public function __construct(?array $order = null, array $excluded = [], array $plans = [])
    {
        $this->plans = $plans;
        $this->excluded = $excluded;

        // If no explicit order but plans are set, derive order from plan tiers
        if ($order === null && ! empty($plans)) {
            $this->order = $this->orderFromPlans();
        } else {
            $this->order = $order ?? ['claude', 'gemini', 'codex'];
        }
    }

    /**
     * Build from environment variables.
     */
    public static function fromEnv(): self
    {
        $order = null;
        $excluded = [];
        $plans = [];

        $pref = getenv('TESSERA_TOOL_PREFERENCE');
        if ($pref !== false && $pref !== '') {
            $order = array_map('trim', explode(',', $pref));
        }

        $excl = getenv('TESSERA_TOOL_EXCLUDE');
        if ($excl !== false && $excl !== '') {
            $excluded = array_map('trim', explode(',', $excl));
        }

        // Read per-tool plans
        $toolEnvMap = [
            'claude' => 'TESSERA_CLAUDE_PLAN',
            'codex' => 'TESSERA_CODEX_PLAN',
            'gemini' => 'TESSERA_GEMINI_PLAN',
        ];

        foreach ($toolEnvMap as $tool => $envVar) {
            $plan = getenv($envVar);
            if ($plan !== false && $plan !== '') {
                $plans[$tool] = strtolower(trim($plan));
            }
        }

        return new self($order, $excluded, $plans);
    }

    /**
     * Get the cost tier for a tool.
     * Returns 'unlimited', 'generous', or 'limited'.
     */
    public function tierFor(string $toolName): string
    {
        $plan = $this->plans[$toolName] ?? 'free';

        return self::PLAN_TIERS[$plan] ?? 'limited';
    }

    /**
     * Check if a tool has an unlimited plan (no cost concern).
     */
    public function isUnlimited(string $toolName): bool
    {
        return $this->tierFor($toolName) === 'unlimited';
    }

    /**
     * Get ordered tool names, filtered by exclusions.
     *
     * @param  array<string>  $availableTools
     * @return array<string>
     */
    public function orderedTools(array $availableTools): array
    {
        $result = [];

        // Add tools in preference order
        foreach ($this->order as $name) {
            if (in_array($name, $availableTools, true) && ! in_array($name, $this->excluded, true)) {
                $result[] = $name;
            }
        }

        // Add any remaining available tools not in the preference order
        foreach ($availableTools as $name) {
            if (! in_array($name, $result, true) && ! in_array($name, $this->excluded, true)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public function plans(): array
    {
        return $this->plans;
    }

    public function describe(): string
    {
        $parts = [];

        if ($this->order !== ['claude', 'gemini', 'codex']) {
            $parts[] = 'preference: '.implode(' > ', $this->order);
        }

        if (! empty($this->excluded)) {
            $parts[] = 'excluded: '.implode(', ', $this->excluded);
        }

        if (! empty($this->plans)) {
            $planParts = [];
            foreach ($this->plans as $tool => $plan) {
                $planParts[] = "{$tool}={$plan}";
            }
            $parts[] = 'plans: '.implode(', ', $planParts);
        }

        return empty($parts) ? 'default' : implode(', ', $parts);
    }

    /**
     * Derive tool preference order from plan tiers.
     * Unlimited plans first, then generous, then limited.
     *
     * @return array<string>
     */
    private function orderFromPlans(): array
    {
        $tools = ['claude', 'gemini', 'codex'];

        usort($tools, function (string $a, string $b): int {
            $tierA = self::TIER_PRIORITY[$this->tierFor($a)] ?? 0;
            $tierB = self::TIER_PRIORITY[$this->tierFor($b)] ?? 0;

            return $tierB <=> $tierA; // Higher tier first
        });

        return $tools;
    }
}
