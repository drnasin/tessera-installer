<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tessera\Installer\Stacks\LaravelStack;

/**
 * Verifies that configureDatabase() restricts the generated .env to
 * owner-only read/write (0600) on POSIX systems — issue #54.
 *
 * Windows is skipped: chmod() is a no-op on Windows and ACL semantics differ.
 */
final class LaravelStackEnvPermissionsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_envperms_'.uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);

        parent::tearDown();
    }

    #[Test]
    public function env_file_is_restricted_to_0600_on_posix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod() is a no-op on Windows; file-permission hardening is POSIX-only.');
        }

        // Seed a minimal .env that configureDatabase() can read and rewrite.
        $envFile = $this->tempDir.DIRECTORY_SEPARATOR.'.env';
        file_put_contents($envFile, "APP_NAME=tessera\nDB_CONNECTION=sqlite\n");

        // Wire up the private properties that configureDatabase() reads.
        $stack = new LaravelStack;

        $fullPathProp = new ReflectionProperty(LaravelStack::class, 'fullPath');
        $fullPathProp->setValue($stack, $this->tempDir);

        $requirementsProp = new ReflectionProperty(LaravelStack::class, 'requirements');
        $requirementsProp->setValue($stack, ['database' => 'sqlite']);

        // Invoke the private method.
        $method = new \ReflectionMethod(LaravelStack::class, 'configureDatabase');
        $method->invoke($stack);

        $mode = fileperms($envFile) & 0777;

        $this->assertSame(
            0600,
            $mode,
            sprintf('.env permissions should be 0600 (owner r/w only), got %04o', $mode),
        );
    }

    private function removeDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
