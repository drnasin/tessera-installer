<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;

/**
 * Go stack for high-performance backends.
 * STATUS: Scaffold-ready. AI generates project, manual steps needed.
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
        return 'High-performance API serveri, microservisi, CLI alati, '
            . 'real-time sustavi, sustavi s visokim brojem istovremenih korisnika. '
            . 'Najbolji izbor za: delivery platforme, payment procesore, '
            . 'chat servere, IoT gatewaye, DevOps alate. '
            . 'Stack: Go 1.22+, Chi/Gin router, sqlc/GORM, PostgreSQL, Docker.';
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

    public function scaffold(string $directory, array $requirements, AiTool $ai): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $desc = $requirements['description'] ?? 'Go backend';

        Console::spinner('AI is generating Go project...');

        if (! @mkdir($fullPath, 0755, true) && ! is_dir($fullPath)) {
            Console::error("Could not create directory: {$fullPath}");

            return false;
        }

        $prompt = <<<PROMPT
Create a complete Go project in the current directory.

DESCRIPTION: {$desc}

Use: Go modules, Chi router, sqlc or GORM, PostgreSQL.
Structure: cmd/, internal/, pkg/, migrations/, docker-compose.yml.
Create: go.mod, main.go, Makefile, Dockerfile, README.md.
Add health check endpoint and graceful shutdown.
PROMPT;

        $response = $ai->execute($prompt, $fullPath, 600);

        if (! $response->success) {
            Console::error('AI scaffold failed: ' . $response->error);

            return false;
        }

        Console::line($response->output);
        Console::success('Go project generated');

        return true;
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;

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
            ],
        ];
    }
}
