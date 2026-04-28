<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\Console;
use Tessera\Installer\Memory;
use Tessera\Installer\SystemInfo;
use Tessera\Installer\ToolRouter;

/**
 * Go stack for high-performance backends.
 *
 * Sprint 1 port: scaffold() delegates to YamlStackRunner; the four
 * heredoc prompts that used to live here are now versioned templates
 * inside `stacks/go.yaml`. Lifecycle (preflight / postSetup /
 * completionInfo) stays in PHP per the round-3 consensus on lifecycle
 * being too quirky to push through YAML in v1.
 */
final class GoStack implements StackInterface
{
    public function name(): string
    {
        return 'go';
    }

    public function label(): string
    {
        return 'Go (Backend / API / Microservice)';
    }

    public function description(): string
    {
        return 'High-performance API servers, microservices, CLI tools, '
            .'real-time systems, systems with high concurrent user counts. '
            .'Best for: delivery platforms, payment processors, '
            .'chat servers, IoT gateways, DevOps tools. '
            .'Stack: Go (latest), Chi/Gin router, sqlc/GORM, PostgreSQL, Docker.';
    }

    public function preflight(): array
    {
        $missing = [];

        $go = Console::execSilent('go version');
        if ($go['exit'] !== 0) {
            $missing[] = 'Go 1.22+ (https://go.dev)';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, ToolRouter $router, SystemInfo $system, Memory $memory): bool
    {
        return (new YamlStackRunner)->run(
            directory: $directory,
            stackName: 'go',
            requirements: $requirements,
            router: $router,
            system: $system,
            memory: $memory,
        );
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd().DIRECTORY_SEPARATOR.$directory;

        Console::spinner('Running go mod tidy...');
        Console::exec('go mod tidy', $fullPath);

        return true;
    }

    public function completionInfo(string $directory): array
    {
        return [
            'commands' => [
                "cd {$directory}",
                'go run cmd/server/main.go',
            ],
            'urls' => [
                'API' => 'http://localhost:8080',
                'Health' => 'http://localhost:8080/health',
                'Setup guide' => 'SETUP.md',
            ],
        ];
    }
}
