<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

/**
 * Registry of all available technology stacks.
 * AI uses this to decide which stack fits the project.
 */
final class StackRegistry
{
    /** @var array<string, StackInterface> */
    private static array $stacks = [];

    private static bool $initialized = false;

    /**
     * Get all registered stacks.
     *
     * @return array<string, StackInterface>
     */
    public static function all(): array
    {
        self::init();

        return self::$stacks;
    }

    /**
     * Get a specific stack by name.
     */
    public static function get(string $name): ?StackInterface
    {
        self::init();

        return self::$stacks[$name] ?? null;
    }

    /**
     * Get stacks that pass preflight checks.
     *
     * @return array<string, StackInterface>
     */
    public static function available(): array
    {
        self::init();

        $available = [];

        foreach (self::$stacks as $name => $stack) {
            $check = $stack->preflight();
            if ($check['ready']) {
                $available[$name] = $stack;
            }
        }

        return $available;
    }

    /**
     * Build the AI context describing all stacks.
     * AI uses this to make technology decisions.
     */
    public static function buildAiContext(): string
    {
        self::init();

        $parts = ['AVAILABLE TECHNOLOGY STACKS:'];
        $parts[] = '';

        foreach (self::$stacks as $name => $stack) {
            $check = $stack->preflight();
            $status = $check['ready'] ? 'READY' : 'MISSING: ' . implode(', ', $check['missing']);

            $parts[] = "### {$stack->label()} ({$name})";
            $parts[] = "Status: {$status}";
            $parts[] = "Description: {$stack->description()}";
            $parts[] = '';
        }

        return implode("\n", $parts);
    }

    /**
     * Register all built-in stacks.
     */
    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::register(new LaravelStack);
        self::register(new NodeStack);
        self::register(new GoStack);
        self::register(new FlutterStack);
        self::register(new StaticStack);

        self::$initialized = true;
    }

    private static function register(StackInterface $stack): void
    {
        self::$stacks[$stack->name()] = $stack;
    }

    /**
     * Reset registry state. For testing only.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$stacks = [];
        self::$initialized = false;
    }
}
