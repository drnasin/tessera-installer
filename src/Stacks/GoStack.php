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
        $langs = implode(', ', $requirements['languages'] ?? ['en']);
        $paymentProviders = $requirements['payment_providers'] ?? [];
        $payments = ! empty($paymentProviders) ? implode(', ', $paymentProviders) : 'none';
        $shop = ($requirements['needs_shop'] ?? false) ? 'YES' : 'NO';
        $country = $requirements['country'] ?? '';
        $needsRealtime = ($requirements['needs_realtime'] ?? false) ? 'YES' : 'NO';
        $goVersion = $this->detectVersions();
        $systemContext = $system->buildAiContext();

        Console::line();
        Console::bold('Building your project — this takes about 5 minutes.');
        Console::line();

        if (! @mkdir($this->fullPath, 0755, true) && ! is_dir($this->fullPath)) {
            Console::error("Could not create directory: {$this->fullPath}");

            return false;
        }

        // Step 1: AI scaffold — senior dev reasoning
        $this->steps->runAi(
            name: '[1/4] Creating project structure',
            prompt: <<<PROMPT
You are a SENIOR Go developer building a production-grade project from scratch.
Think carefully about what THIS specific project needs before writing any code.

{$systemContext}

RUNTIME: {$goVersion}
PROJECT: {$desc}
LANGUAGES: {$langs}
E-COMMERCE: {$shop}
PAYMENT PROVIDERS: {$payments}
COUNTRY: {$country}
REAL-TIME: {$needsRealtime}

STEP 1 — THINK (do not skip):
- What domain entities does this project need? (Users, Products, Orders, Messages, Devices?)
- What API endpoints are needed? (CRUD for each entity? Webhooks? WebSocket?)
- Does it need authentication? (JWT, API keys, OAuth?)
- Does it need payment processing? Which provider SDKs?
- Does it need real-time? (WebSocket, SSE, gRPC streaming?)
- What middleware is needed? (auth, rate limiting, CORS, logging, recovery?)

STEP 2 — CREATE:
1. go.mod with module name and ALL needed dependencies
2. Project structure:
   cmd/server/main.go — entry point with graceful shutdown
   internal/
     config/ — configuration from environment variables
     handler/ — HTTP handlers (one file per domain entity)
     middleware/ — auth, logging, recovery, CORS
     model/ — domain models (structs)
     repository/ — database layer (GORM or sqlc)
     service/ — business logic layer
     router/ — route registration
   migrations/ — SQL migration files
   pkg/ — shared utilities (if needed)

3. Makefile with: build, run, test, migrate, docker-up, docker-down
4. Dockerfile (multi-stage build) + docker-compose.yml (app + PostgreSQL)
5. .env.example with ALL needed environment variables:
   - Database connection
   - JWT secret
   - Payment provider keys (if e-commerce)
   - Port, log level, environment
   Each variable must have a descriptive comment and example value

6. IF E-COMMERCE ({$shop}):
   - Product, Category, Order, OrderItem, Cart models
   - Payment processing with: {$payments}
   - Each payment integration must define its required config keys
   - Webhook handlers for payment notifications
   - Order lifecycle management (created → paid → shipped → delivered)

7. IF REAL-TIME ({$needsRealtime}):
   - WebSocket or SSE endpoint
   - Hub/broker pattern for message distribution
   - Connection management with timeouts

8. Health check endpoint (/health) and readiness probe (/ready)
9. Structured logging (slog)
10. README.md with architecture overview and setup instructions

IMPORTANT: Use modern Go features appropriate for {$goVersion}.
Use generics, slog, and other modern features if the version supports them.
PROMPT,
            verify: function (): ?string {
                return is_file($this->fullPath . '/go.mod') ? null : 'go.mod not created';
            },
            timeout: 600,
        );

        // Step 2: Generate tests
        $this->steps->runAi(
            name: '[2/4] Generating tests',
            prompt: <<<PROMPT
Create Go tests for this project. Read the project structure first.

Create _test.go files next to the code they test:
1. Handler tests — use httptest, test request/response, status codes, validation
2. Service tests — test business logic with mocked repositories
3. Repository tests — test database operations (use test helpers)
4. Integration tests — test full request lifecycle
5. IF e-commerce: test order creation, payment flow, webhook handling

Use table-driven tests. Use testify/assert for cleaner assertions.
IMPORTANT: Write ONLY tests that will PASS with the current codebase.
PROMPT,
            verify: null,
            skippable: true,
            timeout: 300,
        );

        // Step 3: Run tests and fix
        $this->steps->runAi(
            name: '[3/4] Running and fixing tests',
            prompt: <<<PROMPT
Run the project tests with: go test ./...
If any tests fail, analyze the output and fix either the test or the code.
Do NOT delete tests — fix them.
PROMPT,
            verify: null,
            skippable: true,
            timeout: 300,
        );

        // Step 4: SETUP.md — developer handoff
        $this->steps->runAi(
            name: '[4/4] Generating setup instructions',
            prompt: <<<PROMPT
Read the entire project you just built. Generate a SETUP.md file in the project root.

PROJECT: {$desc}
E-COMMERCE: {$shop}
PAYMENT PROVIDERS: {$payments}
COUNTRY: {$country}

SETUP.md must include:

1. QUICK START — commands to build and run, default URLs, test with curl examples
2. ENVIRONMENT VARIABLES — list EVERY .env variable with:
   - Exact key name, what it is, WHERE to get it (with URL), required vs optional
   - Example values for development
3. DATABASE SETUP — PostgreSQL setup, migrations, seed data
4. DOCKER — how to run with docker-compose (the easy way)
5. PAYMENT PROVIDER SETUP (if e-commerce) — for EACH provider ({$payments}):
   - Account creation steps, where to get API keys
   - Test/sandbox credentials
   - Webhook URL to configure (exact URL path)
   - Production switch checklist
6. API DOCUMENTATION — list all endpoints with method, path, request/response examples
7. DEPLOYMENT — Docker, systemd, cloud deployment options
8. PRODUCTION CHECKLIST — security headers, rate limiting, TLS, monitoring, backups
9. COMMON TASKS — how to add a new endpoint, model, migration

Write for a JUNIOR developer. Explain Go-specific concepts briefly.
PROMPT,
            verify: fn (): ?string => is_file($this->fullPath . '/SETUP.md') ? null : 'SETUP.md not created',
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
                'Setup guide' => 'SETUP.md',
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
