<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\NewCommand;

/**
 * Regression tests for NewCommand::removeDirectory() — ensures the recursive
 * delete refuses to escape the current working directory and refuses to follow
 * symlinks.
 *
 * Attack shape: `--force` + malicious `.tessera/state.json` that ends up pointing
 * removeDirectory at an unrelated path. The guard must throw before any rmdir()
 * call lands on something it shouldn't.
 */
final class NewCommandRemoveDirectoryTest extends TestCase
{
    private string $originalCwd;

    private string $sandbox;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_rmdir_'.uniqid('', true);
        mkdir($this->sandbox, 0755, true);
        chdir($this->sandbox);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->bestEffortDelete($this->sandbox);
    }

    private function removeDirectory(string $path): void
    {
        $class = new \ReflectionClass(NewCommand::class);
        $method = $class->getMethod('removeDirectory');
        $method->invoke(null, $path);
    }

    #[Test]
    public function deletes_directory_inside_cwd(): void
    {
        $target = $this->sandbox.DIRECTORY_SEPARATOR.'project';
        mkdir($target);
        file_put_contents($target.DIRECTORY_SEPARATOR.'file.txt', 'data');

        $this->removeDirectory($target);

        $this->assertDirectoryDoesNotExist($target);
    }

    #[Test]
    public function refuses_to_delete_path_outside_cwd(): void
    {
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_outside_'.uniqid('', true);
        mkdir($outside);
        file_put_contents($outside.DIRECTORY_SEPARATOR.'canary.txt', 'do not delete');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/outside current working directory/');

            $this->removeDirectory($outside);
        } finally {
            // Canary must still exist regardless of the exception.
            $this->assertFileExists(
                $outside.DIRECTORY_SEPARATOR.'canary.txt',
                'removeDirectory() must not touch files outside cwd',
            );
            $this->bestEffortDelete($outside);
        }
    }

    #[Test]
    public function refuses_to_delete_parent_of_cwd(): void
    {
        $parent = dirname($this->sandbox);

        $this->expectException(\RuntimeException::class);

        $this->removeDirectory($parent);
    }

    #[Test]
    public function refuses_to_delete_cwd_itself(): void
    {
        file_put_contents($this->sandbox.DIRECTORY_SEPARATOR.'canary.txt', 'do not delete');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/current working directory/');

        try {
            $this->removeDirectory($this->sandbox);
        } finally {
            $this->assertFileExists($this->sandbox.DIRECTORY_SEPARATOR.'canary.txt');
        }
    }

    #[Test]
    public function unlinks_symlink_without_following(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink creation on Windows requires admin/dev mode.');
        }

        // Target lives outside cwd — if removeDirectory follows the symlink,
        // it would try to delete the target's contents (which would trigger
        // the outside-cwd guard) or silently wipe the target.
        $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_symtarget_'.uniqid('', true);
        mkdir($target);
        file_put_contents($target.DIRECTORY_SEPARATOR.'canary.txt', 'do not delete');

        $linkParent = $this->sandbox.DIRECTORY_SEPARATOR.'project';
        mkdir($linkParent);
        $link = $linkParent.DIRECTORY_SEPARATOR.'link';
        symlink($target, $link);

        try {
            $this->removeDirectory($linkParent);

            // Parent should be gone.
            $this->assertDirectoryDoesNotExist($linkParent);
            // Target must be untouched — symlink traversal would have wiped canary.
            $this->assertFileExists($target.DIRECTORY_SEPARATOR.'canary.txt');
        } finally {
            $this->bestEffortDelete($target);
        }
    }

    #[Test]
    public function refuses_symlink_that_resolves_to_cwd(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink creation on Windows requires admin/dev mode.');
        }

        $link = $this->sandbox.DIRECTORY_SEPARATOR.'mylink';
        symlink($this->sandbox, $link);
        file_put_contents($this->sandbox.DIRECTORY_SEPARATOR.'canary.txt', 'do not delete');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/current working directory/');

        try {
            $this->removeDirectory($link);
        } finally {
            $this->assertFileExists($this->sandbox.DIRECTORY_SEPARATOR.'canary.txt');
            if (is_link($link)) {
                @unlink($link);
            }
        }
    }

    #[Test]
    public function silently_returns_for_nonexistent_path(): void
    {
        $this->removeDirectory($this->sandbox.DIRECTORY_SEPARATOR.'does-not-exist');
        $this->assertTrue(true); // no exception == pass
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
            if ($item->isLink()) {
                @unlink($item->getPathname());
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
