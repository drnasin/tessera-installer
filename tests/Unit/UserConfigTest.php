<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\UserConfig;

/**
 * Issue #18 — plan-tier answers are persisted to ~/.tessera/config.json so
 * `tessera new` only asks "What AI plans do you have?" once per machine.
 *
 * Every test sandboxes the home directory in a throwaway temp dir (same pattern
 * as NewCommandStackValidationTest) so the real ~/.tessera is never touched.
 */
final class UserConfigTest extends TestCase
{
    private string $homeDir;

    protected function setUp(): void
    {
        $this->homeDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_userconfig_'.bin2hex(random_bytes(4));
        mkdir($this->homeDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->homeDir);
    }

    #[Test]
    public function save_then_load_round_trips_plans(): void
    {
        $config = new UserConfig($this->homeDir);
        $config->savePlans(['claude' => 'max', 'codex' => 'plus']);

        // Fresh instance forces a read from disk, not in-memory state.
        $reloaded = new UserConfig($this->homeDir);

        $this->assertSame(
            ['claude' => 'max', 'codex' => 'plus'],
            $reloaded->loadPlans(),
        );
    }

    #[Test]
    public function save_writes_to_config_json_under_dot_tessera(): void
    {
        $config = new UserConfig($this->homeDir);
        $config->savePlans(['claude' => 'pro']);

        $expected = $this->homeDir.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'config.json';
        $this->assertFileExists($expected);

        $decoded = json_decode((string) file_get_contents($expected), true);
        $this->assertSame(['claude' => 'pro'], $decoded['plans']);
    }

    #[Test]
    public function load_returns_empty_when_file_missing(): void
    {
        $config = new UserConfig($this->homeDir);

        $this->assertSame([], $config->loadPlans());
    }

    #[Test]
    public function load_tolerates_corrupt_json_by_returning_empty(): void
    {
        $dir = $this->homeDir.DIRECTORY_SEPARATOR.'.tessera';
        mkdir($dir, 0755, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'config.json', '{ this is not valid json');

        $config = new UserConfig($this->homeDir);

        // No exception, just an empty result so the caller falls back to prompting.
        $this->assertSame([], $config->loadPlans());
    }

    #[Test]
    public function load_tolerates_non_array_plans_key(): void
    {
        $dir = $this->homeDir.DIRECTORY_SEPARATOR.'.tessera';
        mkdir($dir, 0755, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'config.json', json_encode(['plans' => 'oops']));

        $config = new UserConfig($this->homeDir);

        $this->assertSame([], $config->loadPlans());
    }

    #[Test]
    public function load_drops_non_string_plan_entries(): void
    {
        $dir = $this->homeDir.DIRECTORY_SEPARATOR.'.tessera';
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir.DIRECTORY_SEPARATOR.'config.json',
            json_encode(['plans' => ['claude' => 'max', 'codex' => 123, '' => 'free']]),
        );

        $config = new UserConfig($this->homeDir);

        $this->assertSame(['claude' => 'max'], $config->loadPlans());
    }

    #[Test]
    public function save_preserves_unrelated_config_keys(): void
    {
        $dir = $this->homeDir.DIRECTORY_SEPARATOR.'.tessera';
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir.DIRECTORY_SEPARATOR.'config.json',
            json_encode(['some_future_key' => 'keep-me', 'plans' => ['claude' => 'free']]),
        );

        $config = new UserConfig($this->homeDir);
        $config->savePlans(['claude' => 'max']);

        $decoded = json_decode((string) file_get_contents($dir.DIRECTORY_SEPARATOR.'config.json'), true);
        $this->assertSame('keep-me', $decoded['some_future_key']);
        $this->assertSame(['claude' => 'max'], $decoded['plans']);
    }

    #[Test]
    public function save_with_empty_plans_round_trips_to_empty(): void
    {
        $config = new UserConfig($this->homeDir);
        $config->savePlans([]);

        $this->assertSame([], (new UserConfig($this->homeDir))->loadPlans());
    }

    #[Test]
    public function for_current_user_resolves_home_env(): void
    {
        $originalHome = getenv('HOME');
        $originalUserProfile = getenv('USERPROFILE');

        putenv('HOME='.$this->homeDir);
        putenv('USERPROFILE='.$this->homeDir);

        try {
            $config = UserConfig::forCurrentUser();
            $this->assertNotNull($config);

            $config->savePlans(['claude' => 'max']);

            $this->assertFileExists($this->homeDir.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'config.json');
        } finally {
            $originalHome === false ? putenv('HOME') : putenv('HOME='.$originalHome);
            $originalUserProfile === false ? putenv('USERPROFILE') : putenv('USERPROFILE='.$originalUserProfile);
        }
    }

    #[Test]
    public function for_current_user_returns_null_without_home(): void
    {
        $originalHome = getenv('HOME');
        $originalUserProfile = getenv('USERPROFILE');

        putenv('HOME=');
        putenv('USERPROFILE=');

        try {
            $this->assertNull(UserConfig::forCurrentUser());
        } finally {
            $originalHome === false ? putenv('HOME') : putenv('HOME='.$originalHome);
            $originalUserProfile === false ? putenv('USERPROFILE') : putenv('USERPROFILE='.$originalUserProfile);
        }
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
