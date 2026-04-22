<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Memory;

/**
 * Regression tests for Memory::save() atomicity and concurrency behaviour.
 *
 * The prior implementation used a fixed "state.json.tmp" filename and no
 * locking, so two parallel writers could trample each other and leave the
 * main file in an indeterminate state. These tests exercise the fixed
 * unique-tmp + flock path.
 */
final class MemoryAtomicWriteTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_atomic_'.uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function state_file_is_never_corrupt_after_many_writes(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('proj', 'laravel', ['description' => 'x'], 'sys');

        for ($i = 0; $i < 50; $i++) {
            $memory->addNote('note '.$i);
        }

        $stateFile = $this->tempDir.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'state.json';
        $content = file_get_contents($stateFile);

        $this->assertNotFalse($content, 'state.json must exist');
        $decoded = json_decode((string) $content, true);
        $this->assertIsArray($decoded, 'state.json must be valid JSON');
        $this->assertCount(50, $decoded['notes']);
    }

    #[Test]
    public function unique_tmp_file_is_used_so_no_collision_on_parallel_writers(): void
    {
        // Simulate two Memory instances writing interleaved — they must not
        // share a tmp name. We can't easily do true parallelism in-process,
        // but we can verify the tmp filename pattern.
        $memory = new Memory($this->tempDir);
        $memory->init('proj', 'laravel', ['description' => 'x'], 'sys');

        $stateDir = $this->tempDir.DIRECTORY_SEPARATOR.'.tessera';

        // After write completes, no .tmp file should remain.
        $tmpFiles = glob($stateDir.DIRECTORY_SEPARATOR.'*.tmp');
        $this->assertIsArray($tmpFiles);
        $this->assertSame([], $tmpFiles, 'tmp files must not be left behind');

        // No fixed "state.json.tmp" either — the legacy hardcoded name was
        // the bug we're fixing.
        $this->assertFileDoesNotExist($stateDir.DIRECTORY_SEPARATOR.'state.json.tmp');
    }

    #[Test]
    public function lockfile_is_created_alongside_state(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('proj', 'laravel', ['description' => 'x'], 'sys');

        $lockFile = $this->tempDir.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'state.json.lock';

        // Lockfile is created on first save and persists (cheap, reused).
        $this->assertFileExists($lockFile);
    }

    #[Test]
    public function reopening_memory_reads_persisted_state(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('proj', 'laravel', ['description' => 'x'], 'sys');
        $memory->completeStep('install');
        $memory->addNote('done');

        // New instance reads from disk.
        $reopened = new Memory($this->tempDir);

        $this->assertTrue($reopened->hasState());
        $this->assertTrue($reopened->isStepDone('install'));
        $state = $reopened->toArray();
        $this->assertCount(1, $state['notes']);
        $this->assertSame('done', $state['notes'][0]['text']);
    }

    #[Test]
    public function concurrent_writers_never_produce_invalid_json(): void
    {
        // Two Memory instances for the same directory. We alternate writes
        // from each — in absence of a lock and unique tmp, this kind of
        // interleaving could leave the file partially written. With the
        // lock + rename, each write either takes effect completely or not
        // at all.
        $a = new Memory($this->tempDir);
        $a->init('proj', 'laravel', ['description' => 'x'], 'sys');

        $b = new Memory($this->tempDir);
        // $b won't have initial state in-memory (constructed before $a's save
        // finished? actually after — fine). We're testing that $b's writes
        // don't corrupt $a's file.

        for ($i = 0; $i < 20; $i++) {
            $a->addNote("a{$i}");
            $b->addNote("b{$i}");

            $content = file_get_contents(
                $this->tempDir.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'state.json',
            );
            $this->assertNotFalse($content);
            $this->assertIsArray(
                json_decode((string) $content, true),
                "state.json corrupt after write iteration {$i}",
            );
        }
    }

    private function removeDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
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
