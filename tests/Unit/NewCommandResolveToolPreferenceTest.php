<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiTool;
use Tessera\Installer\Console;
use Tessera\Installer\FakeConsoleInput;
use Tessera\Installer\NewCommand;
use Tessera\Installer\ToolPreference;
use Tessera\Installer\UserConfig;

/**
 * Issue #18 — NewCommand::resolveToolPreference() precedence and persistence.
 *
 * Precedence asserted here: env vars > saved config (~/.tessera/config.json) >
 * interactive prompt. The detected-tools list is injected so the test never
 * depends on which AI CLIs happen to be installed on the runner; HOME is
 * sandboxed so the real ~/.tessera is untouched. The method is private — it is
 * driven through reflection, the same pattern as NewCommandJsonTest.
 */
final class NewCommandResolveToolPreferenceTest extends TestCase
{
    private string $homeDir;

    private string|false $originalHome;

    private string|false $originalUserProfile;

    protected function setUp(): void
    {
        $this->homeDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_resolvepref_'.bin2hex(random_bytes(4));
        mkdir($this->homeDir.DIRECTORY_SEPARATOR.'.tessera', 0755, true);

        $this->originalHome = getenv('HOME');
        $this->originalUserProfile = getenv('USERPROFILE');
        putenv('HOME='.$this->homeDir);
        putenv('USERPROFILE='.$this->homeDir);

        // Plan env vars must be clean for the saved-config / prompt paths.
        putenv('TESSERA_CLAUDE_PLAN');
        putenv('TESSERA_CODEX_PLAN');
        putenv('TESSERA_GEMINI_PLAN');
        putenv('TESSERA_TOOL_PREFERENCE');
        putenv('TESSERA_TOOL_EXCLUDE');
    }

    protected function tearDown(): void
    {
        Console::setInput(null);

        $this->originalHome === false ? putenv('HOME') : putenv('HOME='.$this->originalHome);
        $this->originalUserProfile === false ? putenv('USERPROFILE') : putenv('USERPROFILE='.$this->originalUserProfile);

        putenv('TESSERA_CLAUDE_PLAN');
        putenv('TESSERA_CODEX_PLAN');
        putenv('TESSERA_GEMINI_PLAN');
        putenv('TESSERA_TOOL_PREFERENCE');
        putenv('TESSERA_TOOL_EXCLUDE');

        $this->removeDir($this->homeDir);
    }

    /**
     * @param  array<string>  $detectedNames
     */
    private function resolve(array $detectedNames): ToolPreference
    {
        $detected = [];
        foreach ($detectedNames as $name) {
            $detected[$name] = AiTool::fake($name);
        }

        $reflection = new \ReflectionClass(NewCommand::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolveToolPreference');

        ob_start();
        $result = $method->invoke($instance, $detected);
        ob_end_clean();

        return $result;
    }

    #[Test]
    public function prompts_and_persists_when_no_saved_config(): void
    {
        // Answers consumed in detection order: claude=0 (max), codex=0 (plus).
        Console::setInput(new FakeConsoleInput([0, 0]));

        $pref = $this->resolve(['claude', 'codex']);

        $this->assertSame(['claude' => 'max', 'codex' => 'plus'], $pref->plans());

        // Persisted for next run.
        $saved = (new UserConfig($this->homeDir))->loadPlans();
        $this->assertSame(['claude' => 'max', 'codex' => 'plus'], $saved);
    }

    #[Test]
    public function saved_config_skips_the_prompt(): void
    {
        (new UserConfig($this->homeDir))->savePlans(['claude' => 'max', 'codex' => 'plus']);

        // Empty queue: if any prompt fired, choice() would return the default
        // (free) and the plans would differ from the saved values.
        Console::setInput(new FakeConsoleInput([]));

        $pref = $this->resolve(['claude', 'codex']);

        $this->assertSame(['claude' => 'max', 'codex' => 'plus'], $pref->plans());
    }

    #[Test]
    public function saved_config_notice_is_printed_when_skipping(): void
    {
        (new UserConfig($this->homeDir))->savePlans(['claude' => 'max', 'codex' => 'plus']);
        Console::setInput(new FakeConsoleInput([]));

        $detected = ['claude' => AiTool::fake('claude'), 'codex' => AiTool::fake('codex')];
        $reflection = new \ReflectionClass(NewCommand::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolveToolPreference');

        ob_start();
        $method->invoke($instance, $detected);
        $output = $this->stripAnsi((string) ob_get_clean());

        $this->assertStringContainsString('Using saved AI plans (claude=max, codex=plus)', $output);
        $this->assertStringContainsString('TESSERA_CLAUDE_PLAN', $output);
    }

    #[Test]
    public function env_vars_beat_saved_config(): void
    {
        (new UserConfig($this->homeDir))->savePlans(['claude' => 'free']);
        putenv('TESSERA_CLAUDE_PLAN=max');

        // Empty queue — env path must short-circuit before any prompt.
        Console::setInput(new FakeConsoleInput([]));

        $pref = $this->resolve(['claude', 'codex']);

        $this->assertSame('max', $pref->plans()['claude']);
        $this->assertTrue($pref->isUnlimited('claude'));
    }

    #[Test]
    public function newly_detected_tool_prompts_only_for_the_missing_one(): void
    {
        // User previously saved claude+codex, then installs gemini.
        (new UserConfig($this->homeDir))->savePlans(['claude' => 'max', 'codex' => 'plus']);

        // Only gemini should be asked. One answer: index 0 => gemini=pro.
        Console::setInput(new FakeConsoleInput([0]));

        $pref = $this->resolve(['claude', 'codex', 'gemini']);

        // Saved answers preserved, new tool merged in.
        $this->assertSame(
            ['claude' => 'max', 'codex' => 'plus', 'gemini' => 'pro'],
            $pref->plans(),
        );

        // Re-saved so a third run asks nothing.
        $saved = (new UserConfig($this->homeDir))->loadPlans();
        $this->assertSame(
            ['claude' => 'max', 'codex' => 'plus', 'gemini' => 'pro'],
            $saved,
        );
    }

    #[Test]
    public function corrupt_config_falls_back_to_prompting(): void
    {
        file_put_contents(
            $this->homeDir.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'config.json',
            '{ corrupt',
        );

        // claude=0 (max). Prompting proves we did not crash on corrupt JSON.
        Console::setInput(new FakeConsoleInput([0]));

        $pref = $this->resolve(['claude']);

        $this->assertSame(['claude' => 'max'], $pref->plans());
    }

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}
