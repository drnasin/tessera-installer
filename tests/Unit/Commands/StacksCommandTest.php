<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Commands;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Commands\StacksCommand;
use Tessera\Installer\Stacks\StackRegistry;

final class StacksCommandTest extends TestCase
{
    #[Test]
    public function description_is_one_line(): void
    {
        $this->assertNotSame('', (new StacksCommand)->description());
    }

    #[Test]
    public function run_returns_zero(): void
    {
        ob_start();
        $code = (new StacksCommand)->run([]);
        ob_end_clean();

        $this->assertSame(0, $code);
    }

    #[Test]
    public function output_contains_every_registry_key(): void
    {
        ob_start();
        $code = (new StacksCommand)->run([]);
        $output = ob_get_clean();

        $this->assertSame(0, $code);

        foreach (array_keys(StackRegistry::all()) as $key) {
            $this->assertStringContainsString($key, $output, "Output is missing stack key: {$key}");
        }
    }

    #[Test]
    public function output_lists_all_five_known_stacks(): void
    {
        ob_start();
        (new StacksCommand)->run([]);
        $output = ob_get_clean();

        foreach (['laravel', 'node', 'go', 'flutter', 'static'] as $key) {
            $this->assertStringContainsString($key, $output);
        }
    }

    #[Test]
    public function output_shows_labels_and_descriptions(): void
    {
        ob_start();
        (new StacksCommand)->run([]);
        $output = ob_get_clean();

        foreach (StackRegistry::all() as $stack) {
            $this->assertStringContainsString($stack->label(), $output);
        }
    }

    #[Test]
    public function help_flag_returns_zero_and_prints_usage(): void
    {
        ob_start();
        $code = (new StacksCommand)->run(['--help']);
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('tessera stacks', $output);
        $this->assertStringContainsString('--stack', $output);
    }

    #[Test]
    public function short_help_flag_returns_zero(): void
    {
        ob_start();
        $code = (new StacksCommand)->run(['-h']);
        ob_end_clean();

        $this->assertSame(0, $code);
    }
}
