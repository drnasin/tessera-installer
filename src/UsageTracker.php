<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Tracks AI tool usage per tool and model during a build.
 */
final class UsageTracker
{
    /** @var array<string, array<string, int>> tool => [model => count] */
    private array $calls = [];

    public function record(string $toolName, ?string $model): void
    {
        $modelKey = $model ?? 'default';

        if (! isset($this->calls[$toolName][$modelKey])) {
            $this->calls[$toolName][$modelKey] = 0;
        }

        $this->calls[$toolName][$modelKey]++;
    }

    public function totalCalls(): int
    {
        $total = 0;

        foreach ($this->calls as $models) {
            foreach ($models as $count) {
                $total += $count;
            }
        }

        return $total;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function toArray(): array
    {
        return $this->calls;
    }

    public function summary(): string
    {
        if (empty($this->calls)) {
            return 'No AI calls made.';
        }

        $parts = [];

        foreach ($this->calls as $tool => $models) {
            $toolTotal = array_sum($models);
            $modelParts = [];

            foreach ($models as $model => $count) {
                $short = $this->shortenModel($model);
                $modelParts[] = "{$count} {$short}";
            }

            $parts[] = "{$tool}: {$toolTotal} calls (".implode(', ', $modelParts).')';
        }

        return implode(' | ', $parts);
    }

    private function shortenModel(string $model): string
    {
        // "claude-opus-4-20250514" → "opus"
        // "claude-sonnet-4-20250514" → "sonnet"
        // "claude-haiku-4-5-20251001" → "haiku"
        // "gemini-2.0-flash" → "flash"
        // "gemini-2.5-pro" → "pro"
        // "default" → "default"
        $map = [
            'opus' => 'opus',
            'sonnet' => 'sonnet',
            'haiku' => 'haiku',
            'flash' => 'flash',
            'pro' => 'pro',
        ];

        foreach ($map as $keyword => $short) {
            if (str_contains($model, $keyword)) {
                return $short;
            }
        }

        return $model;
    }
}
