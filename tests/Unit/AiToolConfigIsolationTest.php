<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiTool;

/**
 * Proves behavioral-config isolation (issue #15) is CALLER-SCOPED on the legacy
 * AiTool path: the requirements interview opts in (isolateConfig: true) and the
 * built claude command carries --safe-mode, while ordinary build/review calls
 * (the default, isolateConfig: false) do NOT — so the generated project's own
 * configuration can still shape the build.
 *
 * Asserting on buildCommand() — the exact argv array execute() hands to
 * proc_open — proves the realised invocation, not just helper return values.
 */
final class AiToolConfigIsolationTest extends TestCase
{
    /** @var string|false */
    private string|false $originalOptOut = false;

    protected function setUp(): void
    {
        $this->originalOptOut = getenv('TESSERA_ISOLATE_AI_CONFIG');
        putenv('TESSERA_ISOLATE_AI_CONFIG');
    }

    protected function tearDown(): void
    {
        if ($this->originalOptOut === false) {
            putenv('TESSERA_ISOLATE_AI_CONFIG');
        } else {
            putenv('TESSERA_ISOLATE_AI_CONFIG='.$this->originalOptOut);
        }
    }

    #[Test]
    public function interview_call_adds_safe_mode_to_claude_command(): void
    {
        $command = AiTool::fake('claude')->buildCommand('a prompt', null, isolateConfig: true);

        $this->assertContains('--safe-mode', $command);
        $this->assertSame('claude', $command[0]);
    }

    #[Test]
    public function build_call_does_not_add_safe_mode_to_claude_command(): void
    {
        // Default isolateConfig=false — the build/review path. The generated
        // project's own CLAUDE.md / skills / MCP must remain available.
        $command = AiTool::fake('claude')->buildCommand('a prompt');

        $this->assertNotContains('--safe-mode', $command);
    }

    #[Test]
    public function interview_call_adds_ignore_user_config_to_codex_command(): void
    {
        $command = AiTool::fake('codex')->buildCommand('a prompt', null, isolateConfig: true);

        $this->assertContains('--ignore-user-config', $command);
        // Codex takes the prompt as the final argv arg (no stdin).
        $this->assertSame('a prompt', $command[array_key_last($command)]);
    }

    #[Test]
    public function interview_call_adds_no_flags_to_gemini_command(): void
    {
        $command = AiTool::fake('gemini')->buildCommand('a prompt', null, isolateConfig: true);

        $this->assertNotContains('--safe-mode', $command);
        $this->assertNotContains('--ignore-user-config', $command);
    }

    #[Test]
    public function opt_out_removes_isolation_even_on_interview_call(): void
    {
        putenv('TESSERA_ISOLATE_AI_CONFIG=0');

        $command = AiTool::fake('claude')->buildCommand('a prompt', null, isolateConfig: true);

        $this->assertNotContains('--safe-mode', $command);
    }

    #[Test]
    public function isolation_coexists_with_model_flag(): void
    {
        $command = AiTool::fake('claude')->buildCommand('a prompt', 'claude-opus-4-8', isolateConfig: true);

        $this->assertContains('--safe-mode', $command);
        $this->assertContains('--model', $command);
        $this->assertContains('claude-opus-4-8', $command);
    }
}
