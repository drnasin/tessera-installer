<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\Memory;
use Tessera\Installer\SystemInfo;
use Tessera\Installer\ToolRouter;

/**
 * Each technology stack implements this interface.
 */
interface StackInterface
{
    /**
     * Unique identifier for this stack.
     */
    public function name(): string;

    /**
     * Human-readable label.
     */
    public function label(): string;

    /**
     * When should AI recommend this stack?
     * Used in the AI prompt to help it decide.
     */
    public function description(): string;

    /**
     * Check if prerequisites are met (runtime, package manager, etc.)
     *
     * @return array{ready: bool, missing: array<string>}
     */
    public function preflight(): array;

    /**
     * Create the project scaffold.
     */
    public function scaffold(string $directory, array $requirements, ToolRouter $router, SystemInfo $system, Memory $memory): bool;

    /**
     * Run post-scaffold setup (migrations, build, etc.)
     */
    public function postSetup(string $directory): bool;

    /**
     * What to show the user when the project is ready.
     *
     * @return array{commands: array<string>, urls: array<string, string>}
     */
    public function completionInfo(string $directory): array;
}
