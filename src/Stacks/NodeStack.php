<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;
use Tessera\Installer\Memory;
use Tessera\Installer\StepRunner;
use Tessera\Installer\SystemInfo;

/**
 * Node.js / Next.js / Express stack.
 */
final class NodeStack implements StackInterface
{
    private StepRunner $steps;

    private string $fullPath;

    private AiTool $ai;

    private SystemInfo $system;

    private Memory $memory;

    /** @var array<string, mixed> */
    private array $requirements;

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
            . 'SaaS platforms, dashboards with React/Vue frontend. '
            . 'Best for: real-time chat, streaming, API-first architectures, '
            . 'JavaScript/TypeScript full-stack. '
            . 'Stack: Node.js (latest), TypeScript, Next.js or Express, Prisma, PostgreSQL.';
    }

    public function preflight(): array
    {
        $missing = [];

        $node = Console::execSilent('node --version');
        if ($node['exit'] !== 0) {
            $missing[] = 'Node.js 20+ (https://nodejs.org)';
        } else {
            $version = trim(str_replace('v', '', $node['output']));
            if (version_compare($version, '20.0.0', '<')) {
                $missing[] = 'Node.js 20+ (found: ' . $version . ')';
            }
        }

        $npm = Console::execSilent('npm --version');
        if ($npm['exit'] !== 0) {
            $missing[] = 'npm';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, AiTool $ai, SystemInfo $system, Memory $memory): bool
    {
        $this->fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $this->ai = $ai;
        $this->system = $system;
        $this->memory = $memory;
        $this->requirements = $requirements;
        $this->steps = new StepRunner($ai, $this->fullPath);

        $memory->init($directory, 'node', $requirements, $system->buildAiContext());

        $desc = $requirements['description'] ?? 'Node.js project';
        $designStyle = $requirements['design_style'] ?? 'modern, clean';
        $designColors = $requirements['design_colors'] ?? 'use appropriate colors for the business type';
        $needsFrontend = ($requirements['needs_frontend'] ?? true) ? 'YES' : 'NO';
        $langs = implode(', ', $requirements['languages'] ?? ['en']);
        $paymentProviders = $requirements['payment_providers'] ?? [];
        $payments = ! empty($paymentProviders) ? implode(', ', $paymentProviders) : 'none';
        $shop = ($requirements['needs_shop'] ?? false) ? 'YES' : 'NO';
        $country = $requirements['country'] ?? '';
        $versions = $this->detectVersions();
        $systemContext = $system->buildAiContext();

        Console::line();
        Console::bold('Building your project — this takes about 5-10 minutes.');
        Console::line();

        // Step 1: Create directory
        if (! @mkdir($this->fullPath, 0755, true) && ! is_dir($this->fullPath)) {
            Console::error("Could not create directory: {$this->fullPath}");

            return false;
        }

        // Step 2: AI scaffold — senior dev reasoning
        $this->steps->runAi(
            name: '[1/4] Creating project structure',
            prompt: <<<PROMPT
You are a SENIOR Node.js/TypeScript developer building a project from scratch.
Think carefully about what THIS specific project needs before writing any code.

{$systemContext}

RUNTIME: {$versions}
PROJECT: {$desc}
LANGUAGES: {$langs}
GENERATE FRONTEND: {$needsFrontend}
DESIGN STYLE: {$designStyle}
DESIGN COLORS: {$designColors}
E-COMMERCE: {$shop}
PAYMENT PROVIDERS: {$payments}
COUNTRY: {$country}

STEP 1 — THINK (do not skip):
- What entities does this project need? (Users, Products, Orders, Posts, Comments?)
- What pages/routes are needed? (Home, Dashboard, Product listing, Cart, Checkout?)
- Does it need authentication? (shop = yes, public API = maybe not)
- Does it need real-time? (chat = yes, blog = no)
- What payment SDKs need to be installed?

STEP 2 — CREATE:
1. package.json with ALL needed dependencies:
   - Framework: Next.js (if frontend) or Express (if API-only)
   - TypeScript, ESLint, Prettier
   - Prisma ORM
   - Authentication (next-auth or passport)
   - IF E-COMMERCE: payment provider SDKs ({$payments})
   - IF real-time: socket.io or ws
2. tsconfig.json, .eslintrc, .prettierrc
3. prisma/schema.prisma — database models appropriate for THIS project
4. src/ structure:
   - API routes/handlers with proper validation (zod)
   - Services/business logic layer
   - IF FRONTEND: pages with Tailwind CSS styling
   - IF E-COMMERCE: cart, checkout, order management, payment integration
5. Docker compose for dev environment (PostgreSQL + app)
6. .env.example with ALL needed environment variables with descriptive comments
7. README.md with setup instructions

IF GENERATE FRONTEND is YES:
- Design style: {$designStyle}, Colors: {$designColors}
- Responsive, mobile-first, professional quality
- Content realistic for the business, in {$langs} — NO lorem ipsum
- Navigation, hero section, footer — all styled and functional

IF E-COMMERCE is YES:
- Product listing with filtering/sorting
- Shopping cart (localStorage + API)
- Checkout flow with payment integration
- Order confirmation page
- Each payment provider must define its required env vars in .env.example
  Example: # Stripe — get keys at https://dashboard.stripe.com/apikeys
           STRIPE_SECRET_KEY=sk_test_...
           STRIPE_PUBLISHABLE_KEY=pk_test_...
           STRIPE_WEBHOOK_SECRET=whsec_...

IMPORTANT: Use features appropriate for the detected Node.js version.
PROMPT,
            verify: function (): ?string {
                return is_file($this->fullPath . '/package.json') ? null : 'package.json not created';
            },
            timeout: 600,
        );

        // Step 3: Generate tests
        $this->steps->runAi(
            name: '[2/4] Generating tests',
            prompt: <<<PROMPT
Create tests for this Node.js project. Read the project structure first to understand what exists.

Use Jest or Vitest (whichever fits the project setup).
Create tests in __tests__/ or tests/ directory:
1. API endpoint tests (if routes exist) — test request/response, status codes, validation
2. Page render tests (if Next.js pages exist) — test that pages render without errors
3. Service/business logic tests — test core logic
4. IF e-commerce: test cart operations, order creation, payment flow (mock external APIs)

IMPORTANT: Write ONLY tests that will PASS with the current codebase.
Do NOT test features that don't exist.
PROMPT,
            verify: null,
            skippable: true,
            timeout: 300,
        );

        // Step 4: Run tests and fix
        $this->steps->runAi(
            name: '[3/4] Running and fixing tests',
            prompt: <<<PROMPT
Run the project tests: npm test (or npx jest or npx vitest run)
If any tests fail, analyze the output and fix either the test or the code.
Do NOT delete tests — fix them. Up to 3 attempts.
PROMPT,
            verify: null,
            skippable: true,
            timeout: 300,
        );

        // Step 5: SETUP.md — developer handoff
        $this->steps->runAi(
            name: '[4/4] Generating setup instructions',
            prompt: <<<PROMPT
Read the entire project you just built. Generate a SETUP.md file in the project root.

PROJECT: {$desc}
E-COMMERCE: {$shop}
PAYMENT PROVIDERS: {$payments}
COUNTRY: {$country}

SETUP.md must include:

1. QUICK START — commands to run, default URLs, test credentials
2. ENVIRONMENT VARIABLES — list EVERY .env variable with:
   - Exact key name, what it is, WHERE to get it (with URL), required vs optional
3. DATABASE SETUP — how to run migrations, seed data
4. PAYMENT PROVIDER SETUP (if e-commerce) — for EACH provider:
   - Account creation steps, where to get API keys
   - Test/sandbox credentials and test card numbers
   - Webhook URL to configure
   - Production switch checklist
5. DEPLOYMENT — Docker, Vercel, or Railway instructions
6. PRODUCTION CHECKLIST — security, env vars, database, SSL, monitoring
7. COMMON TASKS — how to add a new API endpoint, page, model

Write for a JUNIOR developer. Explain briefly when using technical terms.
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

        if (is_file($fullPath . '/package.json')) {
            Console::spinner('Installing npm packages...');
            Console::exec('npm install', $fullPath);
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

    private function detectVersions(): string
    {
        $versions = [];

        $node = Console::execSilent('node --version');
        if ($node['exit'] === 0) {
            $versions[] = 'Node.js ' . trim($node['output']);
        }

        $npm = Console::execSilent('npm --version');
        if ($npm['exit'] === 0) {
            $versions[] = 'npm ' . trim($npm['output']);
        }

        return empty($versions) ? 'Node.js (latest)' : implode(', ', $versions);
    }
}
