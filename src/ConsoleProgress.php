<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * In-place progress indicator for long-running, otherwise-silent operations
 * (notably interactive-phase AI calls, which can take up to 60s with no output).
 *
 * Lifecycle:
 *   $progress = Console::progress('asking claude');
 *   $tool->execute($prompt, $dir, 60, null, $progress->tick(...)); // tick driven from read loop
 *   $progress->finish();
 *
 * TTY vs non-TTY behaviour:
 * - TTY: a single status line (e.g. "⏳ asking claude… (12s)") is redrawn in place
 *   via a leading carriage return, then cleared on finish(). No scrollback spam.
 * - Non-TTY (CI, --requirements-fixture, piped output): one static line is printed
 *   on start and nothing else — no \r, no ANSI cursor control, no timer churn. This
 *   keeps captured output clean and free of control-character garbage.
 *
 * The instance is single-shot: once finished it ignores further ticks.
 */
final class ConsoleProgress
{
    private bool $finished = false;

    private int $lastRenderedWidth = 0;

    /**
     * @param  resource  $stream  Output stream (defaults to STDOUT via factory).
     * @param  bool  $animate  Whether the stream is an interactive TTY.
     */
    public function __construct(
        private readonly string $label,
        private readonly mixed $stream,
        private readonly bool $animate,
    ) {
        if (! $this->animate) {
            // Non-TTY: emit a single static line, no further updates. A bare "\n"
            // (not PHP_EOL) keeps piped/CI output free of carriage returns.
            $this->write("⏳ {$this->label}…\n");
        }
    }

    /**
     * Update the elapsed-time display. Safe to call repeatedly from a polling loop.
     * On a non-TTY stream this is a no-op (the static line was already printed).
     */
    public function tick(int $elapsedSeconds): void
    {
        if ($this->finished || ! $this->animate) {
            return;
        }

        $line = "\033[33m⏳ {$this->label}… ({$elapsedSeconds}s)\033[0m";
        $this->write("\r".$line);
        $this->lastRenderedWidth = mb_strlen("⏳ {$this->label}… ({$elapsedSeconds}s)");
    }

    /**
     * Clear the in-place line (TTY) so the caller's next output starts clean.
     * Idempotent — a second call does nothing.
     */
    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        if (! $this->animate) {
            return;
        }

        // Overwrite the spinner line with spaces, then return the cursor home so
        // the next Console write starts at column 0 with no residual characters.
        if ($this->lastRenderedWidth > 0) {
            $this->write("\r".str_repeat(' ', $this->lastRenderedWidth)."\r");
        } else {
            $this->write("\r");
        }
    }

    private function write(string $text): void
    {
        if (is_resource($this->stream)) {
            fwrite($this->stream, $text);
        }
    }
}
