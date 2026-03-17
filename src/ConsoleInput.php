<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Contract for console input — allows swapping STDIN for test doubles.
 */
interface ConsoleInput
{
    public function ask(string $question, ?string $default = null): string;

    public function confirm(string $question, bool $default = true): bool;

    /**
     * @param  array<int, string>  $options
     */
    public function choice(string $question, array $options, int $default = 0): int;
}
