<?php

declare(strict_types=1);

namespace Tessera\Installer;

final readonly class AiResponse
{
    public function __construct(
        public bool $success,
        public string $output,
        public string $error = '',
        public int $exitCode = 0,
    ) {}
}
