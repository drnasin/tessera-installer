<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Adapters;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tessera\Installer\Adapters\AdapterContext;
use Tessera\Installer\Adapters\ClaudeAdapter;

final class ClaudeAdapterTest extends TestCase
{
    private ?string $previousSafeAi = null;

    protected function setUp(): void
    {
        $val = getenv('TESSERA_SAFE_AI');
        $this->previousSafeAi = $val === false ? null : $val;
        putenv('TESSERA_SAFE_AI');
    }

    protected function tearDown(): void
    {
        if ($this->previousSafeAi === null) {
            putenv('TESSERA_SAFE_AI');
        } else {
            putenv('TESSERA_SAFE_AI='.$this->previousSafeAi);
        }
    }

    #[Test]
    public function name_is_claude(): void
    {
        $this->assertSame('claude', (new ClaudeAdapter)->name());
    }

    #[Test]
    public function default_command_includes_dangerously_skip_permissions(): void
    {
        $command = $this->buildCommand(model: null);

        $this->assertContains('--dangerously-skip-permissions', $command);
        $this->assertSame('claude', $command[0]);
    }

    #[Test]
    public function safe_ai_flag_strips_dangerously_skip_permissions(): void
    {
        putenv('TESSERA_SAFE_AI=1');

        $command = $this->buildCommand(model: null);

        $this->assertNotContains('--dangerously-skip-permissions', $command);
    }

    #[Test]
    public function safe_ai_zero_keeps_dangerously_skip_permissions(): void
    {
        putenv('TESSERA_SAFE_AI=0');

        $command = $this->buildCommand(model: null);

        $this->assertContains('--dangerously-skip-permissions', $command);
    }

    #[Test]
    public function model_flag_inserted_after_binary_name(): void
    {
        $command = $this->buildCommand(model: 'claude-opus-4-20250514');

        $this->assertSame('claude', $command[0]);
        $this->assertSame('--model', $command[1]);
        $this->assertSame('claude-opus-4-20250514', $command[2]);
    }

    #[Test]
    public function uses_stdin_for_prompt_delivery(): void
    {
        $method = new ReflectionMethod(ClaudeAdapter::class, 'usesStdin');

        $this->assertTrue($method->invoke(new ClaudeAdapter));
    }

    #[Test]
    public function supports_any_model_string(): void
    {
        $adapter = new ClaudeAdapter;

        $this->assertTrue($adapter->supportsModel('claude-haiku-4-5-20251001'));
        $this->assertTrue($adapter->supportsModel(null));
        $this->assertTrue($adapter->supportsModel('arbitrary'));
    }

    #[Test]
    public function safe_ai_enabled_returns_true_for_truthy_values(): void
    {
        putenv('TESSERA_SAFE_AI=1');
        $this->assertTrue(ClaudeAdapter::safeAiEnabled());

        putenv('TESSERA_SAFE_AI=true');
        $this->assertTrue(ClaudeAdapter::safeAiEnabled());
    }

    #[Test]
    public function safe_ai_enabled_returns_false_for_falsy_or_unset(): void
    {
        putenv('TESSERA_SAFE_AI');
        $this->assertFalse(ClaudeAdapter::safeAiEnabled());

        putenv('TESSERA_SAFE_AI=');
        $this->assertFalse(ClaudeAdapter::safeAiEnabled());

        putenv('TESSERA_SAFE_AI=0');
        $this->assertFalse(ClaudeAdapter::safeAiEnabled());
    }

    private function buildCommand(?string $model): array
    {
        $context = new AdapterContext(workingDir: '.', model: $model);
        $method = new ReflectionMethod(ClaudeAdapter::class, 'buildExecuteCommand');

        return $method->invoke(new ClaudeAdapter, 'prompt-text', $context);
    }
}
