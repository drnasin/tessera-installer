<?php

declare(strict_types=1);

namespace Tessera\Installer\Adapters;

/**
 * Central registry of available AI CLI adapters.
 *
 * Two responsibilities:
 *   - Default registration of the built-in Claude / Codex / Gemini adapters.
 *   - Open-extension hook: register() accepts third-party adapters
 *     (Groq, Ollama, etc.) without modifying the installer core.
 *
 * Replaces the implicit AiTool::tools() switch with a discoverable,
 * test-friendly registry. The legacy AiTool class continues to work
 * alongside it during the v3.x → v4.0 transition; new code paths
 * (plan compiler, event log emitters, dry-run preview) use the registry.
 */
final class AdapterRegistry
{
    /** @var array<string, AdapterInterface> */
    private array $adapters = [];

    public function __construct(?array $adapters = null)
    {
        if ($adapters === null) {
            $this->registerBuiltins();

            return;
        }

        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public static function default(): self
    {
        return new self;
    }

    public function register(AdapterInterface $adapter): void
    {
        $this->adapters[$adapter->name()] = $adapter;
    }

    public function unregister(string $name): void
    {
        unset($this->adapters[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->adapters[$name]);
    }

    public function get(string $name): ?AdapterInterface
    {
        return $this->adapters[$name] ?? null;
    }

    /**
     * @return array<string, AdapterInterface>
     */
    public function all(): array
    {
        return $this->adapters;
    }

    /**
     * Adapters whose underlying CLI is installed and answers a version probe.
     *
     * @return array<string, AdapterInterface>
     */
    public function available(): array
    {
        $available = [];

        foreach ($this->adapters as $name => $adapter) {
            if ($adapter->isAvailable()) {
                $available[$name] = $adapter;
            }
        }

        return $available;
    }

    /**
     * Names of registered adapters in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->adapters);
    }

    private function registerBuiltins(): void
    {
        $this->register(new ClaudeAdapter);
        $this->register(new GeminiAdapter);
        $this->register(new CodexAdapter);
    }
}
