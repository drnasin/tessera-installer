<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Routes AI tasks to the best available tool + model based on complexity.
 *
 * SIMPLE  → fast/cheap (Haiku, Gemini Flash)
 * MEDIUM  → balanced (Sonnet, Gemini Pro)
 * COMPLEX → best reasoning (Opus, Gemini Pro)
 */
final class ToolRouter
{
    /**
     * Model to use per tool per complexity.
     * null = use tool's default model.
     */
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
     * Tool preference order — best reasoning tools first.
     */
    private const TOOL_PREFERENCE = ['claude', 'gemini', 'codex'];

    /** @var array<string, AiTool> */
    private array $tools;

    /**
     * @param array<string, AiTool> $tools Available tools keyed by name
     */
    public function __construct(array $tools)
    {
        $this->tools = $tools;
    }

    /**
     * Create a router from auto-detection of installed tools.
     */
    public static function detect(): ?self
    {
        $detected = AiTool::detectAllInstances();

        if (empty($detected)) {
            return null;
        }

        return new self($detected);
    }

    /**
     * Create a router with a single tool (backwards compatibility).
     */
    public static function withSingleTool(AiTool $tool): self
    {
        return new self([$tool->name() => $tool]);
    }

    /**
     * Resolve the best tool + model for a given complexity.
     */
    public function resolve(Complexity $complexity): ToolSelection
    {
        foreach (self::TOOL_PREFERENCE as $name) {
            if (isset($this->tools[$name])) {
                $model = self::MODEL_MAP[$name][$complexity->value] ?? null;

                return new ToolSelection($this->tools[$name], $model);
            }
        }

        // Should never happen if constructor got at least one tool
        $first = reset($this->tools);

        return new ToolSelection($first, null);
    }

    /**
     * Get a fallback tool after the primary one failed.
     */
    public function fallback(Complexity $complexity, string $failedTool): ?ToolSelection
    {
        $skipped = false;

        foreach (self::TOOL_PREFERENCE as $name) {
            if ($name === $failedTool) {
                $skipped = true;

                continue;
            }

            if ($skipped && isset($this->tools[$name])) {
                $model = self::MODEL_MAP[$name][$complexity->value] ?? null;

                return new ToolSelection($this->tools[$name], $model);
            }
        }

        return null;
    }

    /**
     * Get the primary tool (first available — for display/conversation use).
     */
    public function primary(): AiTool
    {
        foreach (self::TOOL_PREFERENCE as $name) {
            if (isset($this->tools[$name])) {
                return $this->tools[$name];
            }
        }

        return reset($this->tools);
    }

    /**
     * Get all available tool names.
     *
     * @return array<string>
     */
    public function availableNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Get count of available tools.
     */
    public function count(): int
    {
        return count($this->tools);
    }

    /**
     * Build display string showing routing info.
     */
    public function describe(): string
    {
        $lines = [];

        foreach ([Complexity::SIMPLE, Complexity::MEDIUM, Complexity::COMPLEX] as $complexity) {
            $selection = $this->resolve($complexity);
            $model = $selection->model ? " ({$selection->model})" : '';
            $lines[] = "  {$complexity->value}: {$selection->tool->name()}{$model}";
        }

        return implode("\n", $lines);
    }
}
