<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Minimal console I/O — no framework dependencies.
 *
 * Input methods (ask, confirm, choice) delegate to a swappable ConsoleInput
 * provider, enabling test doubles without touching call sites.
 */
final class Console
{
    private static ?ConsoleInput $input = null;

    private static ?CommandExecutor $commandExecutor = null;

    /**
     * Override for progress-stream TTY detection (for testing). Null = auto-detect.
     */
    private static ?bool $progressAnimateOverride = null;

    /**
     * Swap the input provider (for testing).
     */
    public static function setInput(?ConsoleInput $input): void
    {
        self::$input = $input;
    }

    public static function setCommandExecutor(?CommandExecutor $commandExecutor): void
    {
        self::$commandExecutor = $commandExecutor;
    }

    /**
     * Force progress indicators into TTY (true) or non-TTY (false) mode,
     * or null to restore auto-detection. For testing only.
     */
    public static function setProgressAnimate(?bool $animate): void
    {
        self::$progressAnimateOverride = $animate;
    }

    /**
     * Create an in-place progress indicator for a long-running operation.
     *
     * On a TTY the returned handle redraws a single "⏳ {label}… (Ns)" line via
     * tick() and clears it on finish(). On a non-TTY stream it prints one static
     * line and ignores ticks — keeping CI/fixture output free of control chars.
     *
     * @param  resource|null  $stream  Output stream; defaults to STDOUT.
     */
    public static function progress(string $label, $stream = null): ConsoleProgress
    {
        $stream ??= defined('STDOUT') ? STDOUT : fopen('php://stdout', 'wb');

        return new ConsoleProgress($label, $stream, self::shouldAnimate($stream));
    }

    /**
     * Decide whether progress output should animate (interactive TTY) or print
     * a single static line (non-TTY: CI, pipes, --requirements-fixture).
     *
     * @param  resource  $stream
     */
    private static function shouldAnimate($stream): bool
    {
        if (self::$progressAnimateOverride !== null) {
            return self::$progressAnimateOverride;
        }

        // NO_COLOR / dumb terminals: treat as non-interactive to avoid control chars.
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return false;
        }

        if (! is_resource($stream)) {
            return false;
        }

        return function_exists('stream_isatty') && @stream_isatty($stream);
    }

    public static function line(string $text = ''): void
    {
        echo $text.PHP_EOL;
    }

    public static function info(string $text): void
    {
        echo "\033[32m".$text."\033[0m".PHP_EOL;
    }

    public static function warn(string $text): void
    {
        echo "\033[33m".$text."\033[0m".PHP_EOL;
    }

    public static function error(string $text): void
    {
        echo "\033[31m".$text."\033[0m".PHP_EOL;
    }

    public static function cyan(string $text): void
    {
        echo "\033[36m".$text."\033[0m".PHP_EOL;
    }

    public static function bold(string $text): void
    {
        echo "\033[1m".$text."\033[0m".PHP_EOL;
    }

    public static function spinner(string $text): void
    {
        echo "\033[33m⏳ ".$text."\033[0m".PHP_EOL;
    }

    public static function success(string $text): void
    {
        echo "\033[32m✓ ".$text."\033[0m".PHP_EOL;
    }

    public static function fail(string $text): void
    {
        echo "\033[31m✗ ".$text."\033[0m".PHP_EOL;
    }

    /**
     * Ask user a question and return their answer.
     */
    public static function ask(string $question, ?string $default = null): string
    {
        if (self::$input !== null) {
            return self::$input->ask($question, $default);
        }

        $suffix = $default !== null ? " [{$default}]" : '';
        echo "\033[33m? \033[0m{$question}{$suffix}: ";

        $answer = trim((string) fgets(STDIN));

        if ($answer === '' && $default !== null) {
            return $default;
        }

        return $answer;
    }

    /**
     * Ask yes/no question.
     */
    public static function confirm(string $question, bool $default = true): bool
    {
        if (self::$input !== null) {
            return self::$input->confirm($question, $default);
        }

        $suffix = $default ? ' [Y/n]' : ' [y/N]';
        echo "\033[33m? \033[0m{$question}{$suffix}: ";

        $answer = strtolower(trim((string) fgets(STDIN)));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes', 'da']);
    }

    /**
     * Let user choose from options.
     *
     * @param  array<int, string>  $options
     */
    public static function choice(string $question, array $options, int $default = 0): int
    {
        if (self::$input !== null) {
            return self::$input->choice($question, $options, $default);
        }

        echo PHP_EOL;
        self::line($question);

        foreach ($options as $i => $option) {
            $marker = $i === $default ? "\033[36m → \033[0m" : '   ';
            echo "  {$marker}[{$i}] {$option}".PHP_EOL;
        }

        $answer = self::ask('Choice', (string) $default);

        $index = (int) $answer;

        if (isset($options[$index])) {
            return $index;
        }

        return $default;
    }

    /**
     * Run an argv command and print buffered stdout/stderr after exit.
     *
     * @param  array<int, string>  $argv
     */
    public static function execArgv(
        array $argv,
        ?string $workingDir = null,
        ?EnvPolicy $env = null,
        ?string $stdin = null,
        ?int $timeout = null,
    ): int {
        $result = self::commandExecutor()->run(
            argv: $argv,
            cwd: self::workingDirectory($workingDir),
            env: $env ?? EnvPolicy::buildTool(),
            stdin: $stdin,
            timeout: $timeout,
        );

        if ($result->stdout !== '') {
            echo $result->stdout;
        }

        if ($result->stderr !== '') {
            fwrite(STDERR, $result->stderr);
        }

        return $result->exitCode;
    }

    /**
     * Run an argv command silently and return combined output + exit code.
     *
     * @param  array<int, string>  $argv
     * @return array{output: string, exit: int}
     */
    public static function execSilentArgv(
        array $argv,
        ?string $workingDir = null,
        ?EnvPolicy $env = null,
        ?string $stdin = null,
        ?int $timeout = null,
    ): array {
        $result = self::commandExecutor()->run(
            argv: $argv,
            cwd: self::workingDirectory($workingDir),
            env: $env ?? EnvPolicy::buildTool(),
            stdin: $stdin,
            timeout: $timeout,
        );

        return [
            'output' => $result->combinedOutput(),
            'exit' => $result->exitCode,
        ];
    }

    private static function commandExecutor(): CommandExecutor
    {
        return self::$commandExecutor ??= new CommandRunner();
    }

    private static function workingDirectory(?string $workingDir): string
    {
        if ($workingDir !== null) {
            return $workingDir;
        }

        $cwd = getcwd();

        return is_string($cwd) && $cwd !== '' ? $cwd : '.';
    }

    /**
     * Get first line of a multi-line string.
     * Safe alternative to strtok() which has global state pollution.
     */
    public static function firstLine(string $text): string
    {
        $pos = strpos($text, "\n");

        return $pos !== false ? substr($text, 0, $pos) : $text;
    }
}
