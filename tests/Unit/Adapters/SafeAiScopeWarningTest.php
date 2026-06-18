<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Adapters;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Adapters\AbstractAdapter;
use Tessera\Installer\Adapters\AdapterContext;
use Tessera\Installer\Adapters\CodexAdapter;
use Tessera\Installer\Adapters\GeminiAdapter;

/**
 * Verifies that AbstractAdapter emits a Console::warn() when TESSERA_SAFE_AI
 * is truthy and the resolved tool is codex or gemini (neither honours the flag).
 *
 * The warning must:
 *   - fire exactly once per tool name per process (idempotent),
 *   - not fire for claude (the flag is effective there),
 *   - not fire when TESSERA_SAFE_AI is unset or falsy.
 */
final class SafeAiScopeWarningTest extends TestCase
{
    private ?string $previousSafeAi = null;

    protected function setUp(): void
    {
        $val = getenv('TESSERA_SAFE_AI');
        $this->previousSafeAi = $val === false ? null : $val;
        AbstractAdapter::resetSafeAiWarnedForTesting();
    }

    protected function tearDown(): void
    {
        if ($this->previousSafeAi === null) {
            putenv('TESSERA_SAFE_AI');
        } else {
            putenv('TESSERA_SAFE_AI='.$this->previousSafeAi);
        }

        AbstractAdapter::resetSafeAiWarnedForTesting();
    }

    #[Test]
    public function codex_warns_when_safe_ai_is_set(): void
    {
        putenv('TESSERA_SAFE_AI=1');

        $output = $this->captureWarn(fn () => (new CodexAdapter)->warnIfSafeAiScopeGap());

        $this->assertStringContainsString('TESSERA_SAFE_AI', $output);
        $this->assertStringContainsString('codex', $output);
        $this->assertStringContainsString("tool's own defaults", $output);
    }

    #[Test]
    public function gemini_warns_when_safe_ai_is_set(): void
    {
        putenv('TESSERA_SAFE_AI=1');

        $output = $this->captureWarn(fn () => (new GeminiAdapter)->warnIfSafeAiScopeGap());

        $this->assertStringContainsString('TESSERA_SAFE_AI', $output);
        $this->assertStringContainsString('gemini', $output);
    }

    #[Test]
    public function warning_is_emitted_only_once_per_tool(): void
    {
        putenv('TESSERA_SAFE_AI=1');
        $adapter = new CodexAdapter;

        $first = $this->captureWarn(fn () => $adapter->warnIfSafeAiScopeGap());
        $second = $this->captureWarn(fn () => $adapter->warnIfSafeAiScopeGap());

        $this->assertNotEmpty($first, 'First call should emit the warning.');
        $this->assertSame('', $second, 'Second call should be suppressed.');
    }

    #[Test]
    public function warning_is_emitted_only_once_per_tool_across_adapter_instances(): void
    {
        putenv('TESSERA_SAFE_AI=1');

        $first = $this->captureWarn(fn () => (new CodexAdapter)->warnIfSafeAiScopeGap());
        $second = $this->captureWarn(fn () => (new CodexAdapter)->warnIfSafeAiScopeGap());

        $this->assertNotEmpty($first, 'First instance should emit the warning.');
        $this->assertSame('', $second, 'Second instance should be suppressed.');
    }

    #[Test]
    public function no_warning_when_safe_ai_is_not_set(): void
    {
        putenv('TESSERA_SAFE_AI');

        $output = $this->captureWarn(fn () => (new CodexAdapter)->warnIfSafeAiScopeGap());

        $this->assertSame('', $output);
    }

    #[Test]
    public function no_warning_when_safe_ai_is_zero(): void
    {
        putenv('TESSERA_SAFE_AI=0');

        $output = $this->captureWarn(fn () => (new GeminiAdapter)->warnIfSafeAiScopeGap());

        $this->assertSame('', $output);
    }

    #[Test]
    public function claude_never_warns_even_when_safe_ai_is_set(): void
    {
        putenv('TESSERA_SAFE_AI=1');

        // ClaudeAdapter is not codex/gemini, so no warning expected.
        // We test via a minimal anonymous subclass to avoid touching real Claude.
        $adapter = new class extends AbstractAdapter
        {
            public function name(): string
            {
                return 'claude';
            }

            protected function detectCommand(): array
            {
                return ['claude', '--version'];
            }

            protected function buildExecuteCommand(string $prompt, AdapterContext $context): array
            {
                return ['claude'];
            }

            protected function usesStdin(): bool
            {
                return true;
            }
        };

        $output = $this->captureWarn(fn () => $adapter->warnIfSafeAiScopeGap());

        $this->assertSame('', $output, 'No warning expected for claude.');
    }

    #[Test]
    public function gemini_and_codex_have_separate_warning_slots(): void
    {
        putenv('TESSERA_SAFE_AI=1');

        $codexOutput = $this->captureWarn(fn () => (new CodexAdapter)->warnIfSafeAiScopeGap());
        $geminiOutput = $this->captureWarn(fn () => (new GeminiAdapter)->warnIfSafeAiScopeGap());

        // Both should warn the first time for their respective tool.
        $this->assertStringContainsString('codex', $codexOutput);
        $this->assertStringContainsString('gemini', $geminiOutput);
    }

    /**
     * Capture stdout produced by Console::warn() via output buffering.
     */
    private function captureWarn(callable $fn): string
    {
        ob_start();

        try {
            $fn();
        } finally {
            $output = ob_get_clean();
        }

        return is_string($output) ? $output : '';
    }
}
