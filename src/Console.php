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
