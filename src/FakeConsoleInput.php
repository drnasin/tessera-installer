<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Queue-based console input for testing — no STDIN reads.
 *
 * Each call to ask/confirm/choice shifts the next value from the queue.
 * If the queue is empty, the default is returned.
 */
final class FakeConsoleInput implements ConsoleInput
{
    /** @var list<string|bool|int> */
    private array $queue;

    /**
     * @param  list<string|bool|int>  $answers  Answers in order they'll be consumed.
     */
    public function __construct(array $answers = [])
    {
        $this->queue = $answers;
    }

    public function ask(string $question, ?string $default = null): string
    {
        if ($this->queue !== []) {
            return (string) array_shift($this->queue);
        }

        return $default ?? '';
    }

    public function confirm(string $question, bool $default = true): bool
    {
        if ($this->queue !== []) {
            return (bool) array_shift($this->queue);
        }

        return $default;
    }

    public function choice(string $question, array $options, int $default = 0): int
    {
        if ($this->queue !== []) {
            return (int) array_shift($this->queue);
        }

        return $default;
    }
}
