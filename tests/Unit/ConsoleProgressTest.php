<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Console;

final class ConsoleProgressTest extends TestCase
{
    protected function tearDown(): void
    {
        Console::setProgressAnimate(null);

        parent::tearDown();
    }

    /**
     * Read the full contents written to an in-memory stream.
     *
     * @param  resource  $stream
     */
    private function streamContents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /** @return resource */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'r+b');
        $this->assertIsResource($stream);

        return $stream;
    }

    #[Test]
    public function non_tty_prints_one_static_line_without_carriage_return(): void
    {
        Console::setProgressAnimate(false);
        $stream = $this->memoryStream();

        $progress = Console::progress('asking claude', $stream);
        $progress->tick(0);
        $progress->tick(5);
        $progress->tick(12);
        $progress->finish();

        $output = $this->streamContents($stream);

        $this->assertSame("⏳ asking claude…\n", $output);
        $this->assertStringNotContainsString("\r", $output);
        $this->assertStringNotContainsString("\033", $output);
    }

    #[Test]
    public function tty_animates_in_place_with_carriage_return_and_elapsed_seconds(): void
    {
        Console::setProgressAnimate(true);
        $stream = $this->memoryStream();

        $progress = Console::progress('asking claude', $stream);
        $progress->tick(0);
        $progress->tick(12);

        $output = $this->streamContents($stream);

        $this->assertStringContainsString("\r", $output);
        $this->assertStringContainsString('asking claude', $output);
        $this->assertStringContainsString('(12s)', $output);
        // No trailing newline mid-animation — the line is redrawn in place.
        $this->assertStringNotContainsString(PHP_EOL, $output);
    }

    #[Test]
    public function tty_finish_clears_the_line_and_returns_cursor_home(): void
    {
        Console::setProgressAnimate(true);
        $stream = $this->memoryStream();

        $progress = Console::progress('asking claude', $stream);
        $progress->tick(7);
        $progress->finish();

        $output = $this->streamContents($stream);

        // After finish the rendered line must be overwritten with blanks and the
        // cursor returned to column 0 so subsequent Console output starts clean.
        $this->assertStringEndsWith("\r", $output);
        $this->assertMatchesRegularExpression('/\r {2,}\r$/', $output);
    }

    #[Test]
    public function finish_is_idempotent_and_subsequent_ticks_are_ignored(): void
    {
        Console::setProgressAnimate(true);
        $stream = $this->memoryStream();

        $progress = Console::progress('asking gemini', $stream);
        $progress->tick(3);
        $progress->finish();

        $afterFirstFinish = $this->streamContents($stream);

        // A second finish and any further ticks must not corrupt output.
        $progress->finish();
        $progress->tick(9);

        $this->assertSame($afterFirstFinish, $this->streamContents($stream));
    }

    #[Test]
    public function non_tty_finish_does_not_emit_control_characters(): void
    {
        Console::setProgressAnimate(false);
        $stream = $this->memoryStream();

        $progress = Console::progress('asking codex', $stream);
        $progress->finish();
        $progress->finish();

        $output = $this->streamContents($stream);

        // Only the single static start line — finish() is a no-op on non-TTY.
        $this->assertSame("⏳ asking codex…\n", $output);
        $this->assertStringNotContainsString("\r", $output);
    }

    #[Test]
    public function tty_writes_actual_tool_name_in_the_label(): void
    {
        Console::setProgressAnimate(true);
        $stream = $this->memoryStream();

        $progress = Console::progress('asking gemini', $stream);
        $progress->tick(1);
        $progress->finish();

        $this->assertStringContainsString('asking gemini', $this->streamContents($stream));
    }
}
