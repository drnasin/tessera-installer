<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Minimal console I/O — no framework dependencies.
 */
final class Console
{
    public static function line(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }

    public static function info(string $text): void
    {
        echo "\033[32m" . $text . "\033[0m" . PHP_EOL;
    }

    public static function warn(string $text): void
    {
        echo "\033[33m" . $text . "\033[0m" . PHP_EOL;
    }

    public static function error(string $text): void
    {
        echo "\033[31m" . $text . "\033[0m" . PHP_EOL;
    }

    public static function cyan(string $text): void
    {
        echo "\033[36m" . $text . "\033[0m" . PHP_EOL;
    }

    public static function bold(string $text): void
    {
        echo "\033[1m" . $text . "\033[0m" . PHP_EOL;
    }

    public static function spinner(string $text): void
    {
        echo "\033[33m⏳ " . $text . "\033[0m" . PHP_EOL;
    }

    public static function success(string $text): void
    {
        echo "\033[32m✓ " . $text . "\033[0m" . PHP_EOL;
    }

    public static function fail(string $text): void
    {
        echo "\033[31m✗ " . $text . "\033[0m" . PHP_EOL;
    }

    /**
     * Ask user a question and return their answer.
     */
    public static function ask(string $question, ?string $default = null): string
    {
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
     * @param array<int, string> $options
     */
    public static function choice(string $question, array $options, int $default = 0): int
    {
        echo PHP_EOL;
        self::line($question);

        foreach ($options as $i => $option) {
            $marker = $i === $default ? "\033[36m → \033[0m" : '   ';
            echo "  {$marker}[{$i}] {$option}" . PHP_EOL;
        }

        $answer = self::ask('Izbor', (string) $default);

        $index = (int) $answer;

        if (isset($options[$index])) {
            return $index;
        }

        return $default;
    }

    /**
     * Run a shell command with live output.
     */
    public static function exec(string $command, ?string $workingDir = null): int
    {
        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open($command, $descriptors, $pipes, $workingDir);

        if (! is_resource($process)) {
            self::error("Ne mogu pokrenuti: {$command}");

            return 1;
        }

        return proc_close($process);
    }

    /**
     * Run a shell command silently and return output.
     */
    public static function execSilent(string $command, ?string $workingDir = null): array
    {
        $output = [];
        $exitCode = 0;

        $currentDir = getcwd();

        if ($workingDir) {
            chdir($workingDir);
        }

        exec($command . ' 2>&1', $output, $exitCode);

        if ($workingDir && $currentDir) {
            chdir($currentDir);
        }

        return ['output' => implode(PHP_EOL, $output), 'exit' => $exitCode];
    }
}
