<?php

declare(strict_types=1);

namespace Tessera\Installer;

interface CommandExecutor
{
    /**
     * @param  array<int, string>  $argv
     */
    public function run(
        array $argv,
        string $cwd,
        ?EnvPolicy $env = null,
        ?string $stdin = null,
        ?int $timeout = null,
    ): CommandResult;
}
