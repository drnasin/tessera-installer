<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;
use Tessera\Installer\Memory;
use Tessera\Installer\StepRunner;
use Tessera\Installer\SystemInfo;

/**
 * Go stack for high-performance backends.
 */
final class GoStack implements StackInterface
{
    private StepRunner $steps;

    private string $fullPath;

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
            . 'real-time systems, systems with high concurrent user counts. '
            . 'Best for: delivery platforms, payment processors, '
            . 'chat servers, IoT gateways, DevOps tools. '
            . 'Stack: Go (latest), Chi/Gin router, sqlc/GORM, PostgreSQL, Docker.';
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

    public function scaffold(string $directory, array $requirements, AiTool $ai, SystemInfo $system, Memory $memory): bool
    {
        $this->fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $this->steps = new StepRunner($ai, $this->fullPath);

        $memory->init($directory, 'go', $requirements, $system->buildAiContext());

        $desc = $requirements['description'] ?? 'Go backend';
        $goVersion = $this->detectVersions();
        $systemContext = $system->buildAiContext();

        Console::line();
        Console::bold('Building your project — this takes about 5 minutes.');
        Console::line();

        if (! @mkdir($this->fullPath, 0755, true) && ! is_dir($this->fullPath)) {
            Console::error("Could not create directory: {$this->fullPath}");

            return false;
        }

        // Step 1: AI scaffold
        $this->steps->runAi(
            name: '[1/3] Creating project structure',
            prompt: <<<PROMPT
Create a complete Go project in the current directory.

{$systemContext}

RUNTIME: {$goVersion}
DESCRIPTION: {$desc}

Use: Go modules, Chi router, sqlc or GORM, PostgreSQL.
Structure: cmd/, internal/, pkg/, migrations/, docker-compose.yml.
Create: go.mod, main.go, Makefile, Dockerfile, README.md.
Add health check endpoint and graceful shutdown.

IMPORTANT: Use features appropriate for the detected Go version.
Use generics, slog, and other modern Go features if the version supports them.
PROMPT,
            verify: function (): ?string {
                return is_file($this->fullPath . '/go.mod') ? null : 'go.mod not created';
            },
            timeout: 600,
        );

        // Step 2: Generate tests
        $this->steps->runAi(
            name: '[2/3] Generating tests',
            prompt: <<<PROMPT
Create Go tests for this project.

Create _test.go files next to the code they test:
1. Handler tests (HTTP endpoint tests using httptest)
2. Service/business logic tests
3. Integration tests with test helpers

Use table-driven tests. Use testify if appropriate.
IMPORTANT: Write ONLY tests that will PASS with the current codebase.
PROMPT,
            verify: null,
            skippable: true,
            timeout: 300,
        );

        // Step 3: Run tests
        $this->steps->runAi(
            name: '[3/3] Running and fixing tests',
            prompt: <<<PROMPT
Run the project tests with: go test ./...
If any tests fail, analyze the output and fix either the test or the code.
Do NOT delete tests — fix them.
PROMPT,
            verify: null,
            skippable: true,
            timeout: 300,
        );

        $this->steps->printSummary();

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

    private function detectVersions(): string
    {
        $go = Console::execSilent('go version');
        if ($go['exit'] === 0) {
            return trim($go['output']);
        }

        return 'Go (latest)';
    }
}
