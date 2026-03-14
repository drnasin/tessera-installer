<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * A resolved tool + model pair for a given complexity level.
 */
final readonly class ToolSelection
{
    public function __construct(
        public AiTool $tool,
        public ?string $model,
    ) {}

    public function describe(): string
    {
        $model = $this->model ? basename($this->model) : 'default';

        return "{$this->tool->name()} ({$model})";
    }
}
