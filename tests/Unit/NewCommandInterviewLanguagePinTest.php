<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\NewCommand;

/**
 * The user-facing AI prompts must pin output to English (issue #15).
 *
 * The interview prompts are inline heredocs inside the interactive
 * gatherRequirements()/decideStack() flow, which cannot be driven without a
 * console session. This test instead asserts the prompt-content contract at the
 * source level: every prompt whose AI output is shown to the user verbatim
 * carries an explicit English language pin, so user-level config ("reply in
 * Croatian") cannot make the product voice nondeterministic. It guards against
 * the pin being silently dropped in a future edit.
 */
final class NewCommandInterviewLanguagePinTest extends TestCase
{
    private const PIN = 'Always respond in English';

    private function source(): string
    {
        $file = (new \ReflectionClass(NewCommand::class))->getFileName();
        $this->assertIsString($file);

        $source = file_get_contents($file);
        $this->assertIsString($source);

        return $source;
    }

    #[Test]
    public function interview_init_prompt_pins_english(): void
    {
        $source = $this->source();

        // Anchor on a unique line from the init interview prompt, then require
        // the pin within the same heredoc block.
        $initStart = strpos($source, 'A junior developer needs your help');
        $this->assertNotFalse($initStart, 'init interview prompt not found');

        $block = substr($source, $initStart, 600);
        $this->assertStringContainsString(self::PIN, $block);
    }

    #[Test]
    public function interview_follow_up_prompt_pins_english(): void
    {
        $source = $this->source();

        $followStart = strpos($source, 'You are a senior developer talking with a junior');
        $this->assertNotFalse($followStart, 'follow-up interview prompt not found');

        $block = substr($source, $followStart, 600);
        $this->assertStringContainsString(self::PIN, $block);
    }

    #[Test]
    public function stack_decision_reason_pins_english(): void
    {
        $source = $this->source();

        // The decideStack "reason" field is printed verbatim via Console::line.
        $decideStart = strpos($source, 'choose ONE technology');
        $this->assertNotFalse($decideStart, 'decideStack prompt not found');

        $block = substr($source, $decideStart, 400);
        $this->assertStringContainsString('English', $block);
    }

    #[Test]
    public function only_voice_prompts_request_config_isolation(): void
    {
        // Scope guard (issue #15, Codex diff review): isolation must be caller-
        // scoped. The three user-facing-voice calls (init, follow-up, decideStack)
        // pass isolateConfig: true; the JSON-extract and dependency-install calls
        // do NOT — dependency install legitimately benefits from the user's
        // environment, and its output is never shown as product voice.
        $source = $this->source();

        $isolatedCalls = preg_match_all('/askPrimary\([^;]*isolateConfig:\s*true/', $source);
        $this->assertSame(3, $isolatedCalls, 'expected exactly 3 isolated askPrimary calls');

        // The dependency-install prompt (300s timeout) must NOT be isolated.
        $autoInstallStart = strpos($source, 'private function autoInstallDependencies');
        $this->assertNotFalse($autoInstallStart);
        $autoInstallBody = substr($source, $autoInstallStart, 1500);
        $this->assertStringContainsString('askPrimary($prompt, 300)', $autoInstallBody);
        $this->assertStringNotContainsString('isolateConfig', $autoInstallBody);
    }
}
