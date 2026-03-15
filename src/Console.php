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
     * @param  array<int, string>  $options
     */
    public static function choice(string $question, array $options, int $default = 0): int
    {
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
     * Run a shell command with live output.
     * Environment is cleaned to prevent leaking secrets to subprocesses.
     */
    public static function exec(string $command, ?string $workingDir = null): int
    {
        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open($command, $descriptors, $pipes, $workingDir, self::cleanEnv());

        if (! is_resource($process)) {
            self::error("Could not start: {$command}");

            return 1;
        }

        return proc_close($process);
    }

    /**
     * Run a shell command silently and return output.
     */
    public static function execSilent(string $command, ?string $workingDir = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $workingDir, self::cleanEnv());

        if (! is_resource($process)) {
            return ['output' => "Could not start: {$command}", 'exit' => 1];
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $result = trim(($output ?: '').($stderr ? "\n".$stderr : ''));

        return ['output' => $result, 'exit' => $exitCode];
    }

    /**
     * Build clean environment without AI nesting markers or secrets.
     *
     * @return array<string, string>
     */
    private static function cleanEnv(): array
    {
        $env = getenv();

        if (! is_array($env)) {
            return [];
        }

        // Remove AI nesting protection vars
        $remove = [
            'CLAUDECODE',
            'CLAUDE_CODE',
            'CLAUDE_CODE_SSE_PORT',
            'CLAUDE_CODE_ENTRYPOINT',
            'VIPSHOME',
        ];

        foreach ($remove as $var) {
            unset($env[$var]);
        }

        return $env;
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
