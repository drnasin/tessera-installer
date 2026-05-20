<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\Console;
use Tessera\Installer\EnvPolicy;
use Tessera\Installer\Memory;
use Tessera\Installer\SystemInfo;
use Tessera\Installer\ToolRouter;

/**
 * Node.js / Next.js / Express stack.
 *
 * Sprint 1 port: scaffold() delegates to YamlStackRunner. The four
 * heredoc prompts that used to live here are now versioned templates
 * inside `stacks/node.yaml`. Lifecycle (preflight / postSetup /
 * completionInfo) stays in PHP per the round-3 consensus.
 */
final class NodeStack implements StackInterface
{
    public function name(): string
    {
        return 'node';
    }

    public function label(): string
    {
        return 'Node.js (Next.js / Express)';
    }

    public function description(): string
    {
        return 'API servers, real-time applications, SSR web apps, '
            .'SaaS platforms, dashboards with React/Vue frontend. '
            .'Best for: real-time chat, streaming, API-first architectures, '
            .'JavaScript/TypeScript full-stack. '
            .'Stack: Node.js (latest), TypeScript, Next.js or Express, Prisma, PostgreSQL.';
    }

    public function preflight(): array
    {
        $missing = [];

        $node = Console::execSilentArgv(['node', '--version'], env: EnvPolicy::minimal());
        if ($node['exit'] !== 0) {
            $missing[] = 'Node.js 20+ (https://nodejs.org)';
        } else {
            $version = trim(str_replace('v', '', $node['output']));
            if (version_compare($version, '20.0.0', '<')) {
                $missing[] = 'Node.js 20+ (found: '.$version.')';
            }
        }

        $npm = Console::execSilentArgv(['npm', '--version'], env: EnvPolicy::minimal());
        if ($npm['exit'] !== 0) {
            $missing[] = 'npm';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, ToolRouter $router, SystemInfo $system, Memory $memory): bool
    {
        return (new YamlStackRunner)->run(
            directory: $directory,
            stackName: 'node',
            requirements: $requirements,
            router: $router,
            system: $system,
            memory: $memory,
        );
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd().DIRECTORY_SEPARATOR.$directory;

        if (is_file($fullPath.'/package.json')) {
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
                'App' => 'http://localhost:3000',
                'Setup guide' => 'SETUP.md',
            ],
        ];
    }
}
