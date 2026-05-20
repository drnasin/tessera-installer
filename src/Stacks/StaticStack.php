<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\Console;
use Tessera\Installer\EnvPolicy;
use Tessera\Installer\Memory;
use Tessera\Installer\SystemInfo;
use Tessera\Installer\ToolRouter;

/**
 * Static site (HTML + Tailwind + Alpine.js).
 *
 * Sprint 1 port: scaffold() delegates to YamlStackRunner, which loads
 * `stacks/static.yaml`, compiles a plan, and dispatches each step
 * through the adapter pipeline. The PHP heredoc prompts that used to
 * live here are now templates inside static.yaml. Lifecycle methods
 * (preflight / postSetup / completionInfo) stay in PHP per round-3
 * consensus — those are too quirky to push through YAML in v1.
 */
final class StaticStack implements StackInterface
{
    public function name(): string
    {
        return 'static';
    }

    public function label(): string
    {
        return 'Static Site (HTML + Tailwind)';
    }

    public function description(): string
    {
        return 'Simple landing pages, portfolio sites, event pages, '
            .'coming soon pages — no backend, no database. '
            .'Best for: quick one-off pages, campaigns, '
            .'personal portfolio sites, event invitations. '
            .'Stack: HTML5, Tailwind CSS (latest), Alpine.js, Vite. Deploy: Netlify/Vercel/GitHub Pages.';
    }

    public function preflight(): array
    {
        $missing = [];

        $npm = Console::execSilentArgv(['npm', '--version'], env: EnvPolicy::minimal());
        if ($npm['exit'] !== 0) {
            $missing[] = 'Node.js + npm (https://nodejs.org)';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, ToolRouter $router, SystemInfo $system, Memory $memory): bool
    {
        return (new YamlStackRunner)->run(
            directory: $directory,
            stackName: 'static',
            requirements: $requirements,
            router: $router,
            system: $system,
            memory: $memory,
        );
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd().DIRECTORY_SEPARATOR.$directory;

        if (file_exists($fullPath.'/package.json')) {
            Console::spinner('Installing npm packages...');
            Console::execArgv(['npm', 'install'], $fullPath);
        }

        return true;
    }

    public function completionInfo(string $directory): array
    {
        return [
            'commands' => [
                "cd {$directory}",
                'npm run dev',
            ],
            'urls' => [
                'Dev' => 'http://localhost:5173',
                'Build' => 'npm run build',
                'Setup guide' => 'SETUP.md',
            ],
        ];
    }
}
