<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Adapters;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Adapters\AdapterContext;
use Tessera\Installer\Adapters\AdapterInterface;
use Tessera\Installer\Adapters\AdapterRegistry;
use Tessera\Installer\Adapters\ClaudeAdapter;
use Tessera\Installer\Adapters\CodexAdapter;
use Tessera\Installer\Adapters\GeminiAdapter;
use Tessera\Installer\AiResponse;

final class AdapterRegistryTest extends TestCase
{
    #[Test]
    public function default_registry_registers_three_builtins(): void
    {
        $registry = AdapterRegistry::default();

        $this->assertSame(['claude', 'gemini', 'codex'], $registry->names());
        $this->assertInstanceOf(ClaudeAdapter::class, $registry->get('claude'));
        $this->assertInstanceOf(GeminiAdapter::class, $registry->get('gemini'));
        $this->assertInstanceOf(CodexAdapter::class, $registry->get('codex'));
    }

    #[Test]
    public function explicit_construction_skips_builtins(): void
    {
        $registry = new AdapterRegistry([new ClaudeAdapter]);

        $this->assertSame(['claude'], $registry->names());
        $this->assertFalse($registry->has('gemini'));
    }

    #[Test]
    public function register_replaces_existing_adapter_with_same_name(): void
    {
        $registry = new AdapterRegistry([]);
        $first = $this->fakeAdapterNamed('mock');
        $second = $this->fakeAdapterNamed('mock');

        $registry->register($first);
        $registry->register($second);

        $this->assertSame($second, $registry->get('mock'));
        $this->assertCount(1, $registry->all());
    }

    #[Test]
    public function unregister_removes_adapter(): void
    {
        $registry = AdapterRegistry::default();

        $registry->unregister('codex');

        $this->assertFalse($registry->has('codex'));
        $this->assertNull($registry->get('codex'));
    }

    #[Test]
    public function available_filters_to_isavailable_true(): void
    {
        $registry = new AdapterRegistry([
            $this->fakeAdapterNamed('one', available: true),
            $this->fakeAdapterNamed('two', available: false),
            $this->fakeAdapterNamed('three', available: true),
        ]);

        $available = $registry->available();

        $this->assertSame(['one', 'three'], array_keys($available));
    }

    private function fakeAdapterNamed(string $name, bool $available = true): AdapterInterface
    {
        return new readonly class($name, $available) implements AdapterInterface
        {
            public function __construct(private string $n, private bool $a) {}

            public function name(): string
            {
                return $this->n;
            }

            public function version(): ?string
            {
                return 'fake-1.0';
            }

            public function isAvailable(): bool
            {
                return $this->a;
            }

            public function supportsModel(?string $model): bool
            {
                return true;
            }

            public function execute(string $prompt, AdapterContext $context): AiResponse
            {
                return new AiResponse(true, 'fake');
            }

            public function estimateCost(int $estimatedInputTokens, ?int $estimatedOutputTokens = null): ?float
            {
                return null;
            }
        };
    }
}
