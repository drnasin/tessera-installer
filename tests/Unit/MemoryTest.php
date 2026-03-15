<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Memory;

final class MemoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function has_state_returns_false_for_new_memory(): void
    {
        $memory = new Memory($this->tempDir);

        $this->assertFalse($memory->hasState());
    }

    #[Test]
    public function init_creates_state_file(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test-project', 'laravel', ['description' => 'Test'], 'system context');

        $stateFile = $this->tempDir.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'state.json';
        $this->assertFileExists($stateFile);
    }

    #[Test]
    public function has_state_returns_true_after_init(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test-project', 'laravel', [], '');

        $this->assertTrue($memory->hasState());
    }

    #[Test]
    public function init_sets_correct_structure(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test-project', 'laravel', ['desc' => 'Test'], 'sys');

        $state = $memory->toArray();
        $this->assertSame('test-project', $state['project']);
        $this->assertSame('laravel', $state['stack']);
        $this->assertSame('in_progress', $state['status']);
        $this->assertSame(['desc' => 'Test'], $state['requirements']);
        $this->assertIsArray($state['completed_steps']);
        $this->assertEmpty($state['completed_steps']);
    }

    #[Test]
    public function step_lifecycle(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', [], '');

        $this->assertFalse($memory->isStepDone('packages'));

        $memory->startStep('packages');
        $this->assertFalse($memory->isStepDone('packages'));

        $memory->completeStep('packages');
        $this->assertTrue($memory->isStepDone('packages'));
    }

    #[Test]
    public function is_step_done_returns_false_for_unknown_step(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', [], '');

        $this->assertFalse($memory->isStepDone('nonexistent'));
    }

    #[Test]
    public function fail_step_records_error(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', [], '');
        $memory->failStep('theme', 'Timeout after 900s');

        $state = $memory->toArray();
        $this->assertCount(1, $state['failed_steps']);
        $this->assertSame('theme', $state['failed_steps'][0]['name']);
        $this->assertSame('Timeout after 900s', $state['failed_steps'][0]['error']);
    }

    #[Test]
    public function skip_step_records_reason(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', [], '');
        $memory->skipStep('tests', 'User chose to skip');

        $state = $memory->toArray();
        $this->assertCount(1, $state['skipped_steps']);
        $this->assertSame('tests', $state['skipped_steps'][0]['name']);
    }

    #[Test]
    public function record_decision(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', [], '');
        $memory->recordDecision('database', 'mysql', 'user has mysql installed');

        $state = $memory->toArray();
        $this->assertCount(1, $state['decisions']);
        $this->assertSame('database', $state['decisions'][0]['what']);
        $this->assertSame('mysql', $state['decisions'][0]['decision']);
    }

    #[Test]
    public function add_note(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', [], '');
        $memory->addNote('Shop module created with 8 models');
        $memory->addNote('Using CorvusPay for payments');

        $state = $memory->toArray();
        $this->assertCount(2, $state['notes']);
    }

    #[Test]
    public function complete_marks_status(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', [], '');
        $memory->complete();

        $state = $memory->toArray();
        $this->assertSame('complete', $state['status']);
        $this->assertArrayHasKey('completed_at', $state);
    }

    #[Test]
    public function fail_marks_status_and_reason(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', [], '');
        $memory->fail('Build failed on step 3');

        $state = $memory->toArray();
        $this->assertSame('failed', $state['status']);
        $this->assertSame('Build failed on step 3', $state['failure_reason']);
    }

    #[Test]
    public function build_ai_context_includes_completed_steps(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', [], '');
        $memory->completeStep('packages');
        $memory->completeStep('filament');

        $context = $memory->buildAiContext();
        $this->assertStringContainsString('packages', $context);
        $this->assertStringContainsString('filament', $context);
    }

    #[Test]
    public function build_ai_context_returns_empty_for_no_state(): void
    {
        $memory = new Memory($this->tempDir);

        $this->assertSame('', $memory->buildAiContext());
    }

    #[Test]
    public function update_context_preserves_progress(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', ['old' => true], '');
        $memory->completeStep('packages');
        $memory->completeStep('filament');

        $memory->updateContext(['new' => true], 'updated system');

        $this->assertTrue($memory->isStepDone('packages'));
        $this->assertTrue($memory->isStepDone('filament'));
        $this->assertSame('in_progress', $memory->toArray()['status']);
        $this->assertSame(['new' => true], $memory->toArray()['requirements']);
    }

    #[Test]
    public function state_persists_across_instances(): void
    {
        $memory1 = new Memory($this->tempDir);
        $memory1->init('test', 'laravel', ['desc' => 'A project'], '');
        $memory1->completeStep('packages');

        // New instance, same directory
        $memory2 = new Memory($this->tempDir);

        $this->assertTrue($memory2->hasState());
        $this->assertTrue($memory2->isStepDone('packages'));
        $this->assertSame('test', $memory2->toArray()['project']);
    }

    #[Test]
    public function corrupted_json_returns_empty_state(): void
    {
        $stateDir = $this->tempDir.DIRECTORY_SEPARATOR.'.tessera';
        mkdir($stateDir, 0755, true);
        file_put_contents($stateDir.DIRECTORY_SEPARATOR.'state.json', 'not valid json {{{');

        $memory = new Memory($this->tempDir);

        $this->assertFalse($memory->hasState());
    }

    #[Test]
    public function record_install(): void
    {
        $memory = new Memory($this->tempDir);
        $memory->init('test', 'laravel', [], '');
        $memory->recordInstall('filament/filament', '5.3.5');

        $state = $memory->toArray();
        $this->assertCount(1, $state['installed_dependencies']);
        $this->assertSame('filament/filament', $state['installed_dependencies'][0]['tool']);
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
