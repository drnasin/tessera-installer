<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * User-configurable AI tool preferences.
 *
 * Set via environment variables:
 *   TESSERA_TOOL_PREFERENCE=gemini,claude,codex  (order)
 *   TESSERA_TOOL_EXCLUDE=codex                   (never use)
 */
final class ToolPreference
{
    /** @var array<string> */
    private array $order;

    /** @var array<string> */
    private array $excluded;

    /**
     * @param  array<string>|null  $order  Custom preference order (null = default)
     * @param  array<string>  $excluded  Tools to exclude
     */
    public function __construct(?array $order = null, array $excluded = [])
    {
        $this->order = $order ?? ['claude', 'gemini', 'codex'];
        $this->excluded = $excluded;
    }

    /**
     * Build from environment variables.
     */
    public static function fromEnv(): self
    {
        $order = null;
        $excluded = [];

        $pref = getenv('TESSERA_TOOL_PREFERENCE');
        if ($pref !== false && $pref !== '') {
            $order = array_map('trim', explode(',', $pref));
        }

        $excl = getenv('TESSERA_TOOL_EXCLUDE');
        if ($excl !== false && $excl !== '') {
            $excluded = array_map('trim', explode(',', $excl));
        }

        return new self($order, $excluded);
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

    public function describe(): string
    {
        $parts = [];

        if ($this->order !== ['claude', 'gemini', 'codex']) {
            $parts[] = 'preference: '.implode(' > ', $this->order);
        }

        if (! empty($this->excluded)) {
            $parts[] = 'excluded: '.implode(', ', $this->excluded);
        }

        return empty($parts) ? 'default' : implode(', ', $parts);
    }
}
