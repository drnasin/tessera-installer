<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Console;
use Tessera\Installer\ConsoleInput;
use Tessera\Installer\NewCommand;
use Tessera\Installer\Stacks\StackRegistry;

/**
 * Issue #14 — an invalid `--stack` value must fail fast.
 *
 * Before the fix, the full wizard ran (plan-tier questions + the entire
 * token-burning AI interview) and only `buildProject()` warned, after the
 * fact, that the stack was unknown. The guard in NewCommand::run() now
 * rejects an unknown forced stack before any prompt, system check, or AI
 * call.
 */
final class NewCommandStackValidationTest extends TestCase
{
    protected function tearDown(): void
    {
        Console::setInput(null);

        parent::tearDown();
    }

    #[Test]
    public function invalid_forced_stack_exits_one_immediately_without_prompting(): void
    {
        $recorder = new RecordingConsoleInput;
        Console::setInput($recorder);

        $command = new NewCommand('myproj', forcedStack: 'rails');

        ob_start();
        $exit = $command->run();
        $output = $this->stripAnsi((string) ob_get_clean());

        $this->assertSame(1, $exit);

        // No interactive prompt fired — the guard returned before the banner,
        // the first-run notice, preflight, and the AI interview.
        $this->assertSame(0, $recorder->calls, 'No prompt should be triggered for an invalid stack.');

        // The error lists the valid stack names sourced from the registry,
        // not a hardcoded list.
        $expectedNames = implode(', ', array_keys(StackRegistry::all()));
        $this->assertStringContainsString("Unknown stack 'rails'. Available stacks: {$expectedNames}", $output);
        $this->assertStringContainsString('laravel', $output);

        // The wizard never started.
        $this->assertStringNotContainsString('TESSERA — AI Architect', $output);
    }

    #[Test]
    public function valid_forced_stack_passes_the_guard(): void
    {
        // Sandbox HOME so showFirstRunNotice() reads/writes its marker file in
        // a throwaway dir instead of the real home directory. Pre-seed the
        // marker so the notice is skipped entirely (it would otherwise call
        // confirm()).
        $home = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_home_'.bin2hex(random_bytes(4));
        mkdir($home.DIRECTORY_SEPARATOR.'.tessera', 0755, true);
        file_put_contents($home.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'.notice-accepted', date('Y-m-d H:i:s'));

        $originalHome = getenv('HOME');
        $originalUserProfile = getenv('USERPROFILE');
        putenv('HOME='.$home);
        putenv('USERPROFILE='.$home);

        $recorder = new RecordingConsoleInput;
        Console::setInput($recorder);

        // Point at a non-existent requirements fixture so the run stops at the
        // fixture-load step instead of launching the real AI interview. This
        // keeps the test deterministic and token-free regardless of the AI
        // tooling installed on the machine — we only care that execution got
        // past the early stack guard.
        $command = new NewCommand(
            'myproj-valid-'.bin2hex(random_bytes(3)),
            forcedStack: 'laravel',
            requirementsFixturePath: sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_missing_fixture_'.bin2hex(random_bytes(4)).'.json',
        );

        try {
            ob_start();
            $command->run();
            $output = $this->stripAnsi((string) ob_get_clean());
        } finally {
            $originalHome === false ? putenv('HOME') : putenv('HOME='.$originalHome);
            $originalUserProfile === false ? putenv('USERPROFILE') : putenv('USERPROFILE='.$originalUserProfile);
            $this->bestEffortDelete($home);
        }

        // A valid stack is NOT rejected by the early guard: execution proceeds
        // past it into the normal pipeline (banner is printed). Whether the
        // run ultimately succeeds depends on the machine's AI tooling, which is
        // out of scope here — we only assert the guard let it through.
        $this->assertStringNotContainsString('Unknown stack', $output);
        $this->assertStringContainsString('TESSERA — AI Architect', $output);
    }

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    private function bestEffortDelete(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($rii as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}

/**
 * Console input double that records whether any prompt method was invoked.
 * Used to prove the invalid-stack guard returns before any interaction.
 */
final class RecordingConsoleInput implements ConsoleInput
{
    public int $calls = 0;

    public function ask(string $question, ?string $default = null): string
    {
        $this->calls++;

        return $default ?? '';
    }

    public function confirm(string $question, bool $default = true): bool
    {
        $this->calls++;

        return $default;
    }

    public function choice(string $question, array $options, int $default = 0): int
    {
        $this->calls++;

        return $default;
    }
}
