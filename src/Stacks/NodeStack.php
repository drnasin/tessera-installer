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
        $this->requirements = $requirements;
        $this->steps = new StepRunner($ai, $this->fullPath);

        $memory->init($directory, 'node', $requirements, $system->buildAiContext());

        $desc = $requirements['description'] ?? 'Node.js project';
        $designStyle = $requirements['design_style'] ?? 'modern, clean';
        $designColors = $requirements['design_colors'] ?? 'use appropriate colors for the business type';
        $needsFrontend = ($requirements['needs_frontend'] ?? true) ? 'YES' : 'NO';
        $langs = implode(', ', $requirements['languages'] ?? ['en']);
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

        // Step 2: AI scaffold
        $this->steps->runAi(
            name: '[1/3] Creating project structure',
            prompt: <<<PROMPT
Create a complete Node.js project in the current directory.

{$systemContext}

RUNTIME: {$versions}
DESCRIPTION: {$desc}
LANGUAGES: {$langs}
GENERATE FRONTEND: {$needsFrontend}
DESIGN STYLE: {$designStyle}
DESIGN COLORS: {$designColors}

Use: TypeScript, Next.js (or Express if API-only), Prisma ORM, PostgreSQL.
Create: package.json, tsconfig.json, basic structure, README.md with instructions.
Set up ESLint + Prettier. Add Docker compose for dev environment.

If GENERATE FRONTEND is YES:
- Create styled pages with the specified design style and colors
- Use Tailwind CSS for styling
- Make it responsive and professional-looking
- Content should be realistic for the project description, in {$langs}

IMPORTANT: Use features appropriate for the detected Node.js version.
PROMPT,
            verify: function (): ?string {
                return is_file($this->fullPath . '/package.json') ? null : 'package.json not created';
            },
            timeout: 600,
        );

        // Step 3: Generate tests
        $this->steps->runAi(
            name: '[2/3] Generating tests',
            prompt: <<<PROMPT
Create tests for this Node.js project.

Use Jest or Vitest (whichever is more appropriate for the project setup).
Create tests in __tests__/ or tests/ directory:
1. API endpoint tests (if Express/API routes exist)
2. Page render tests (if Next.js pages exist)
3. Utility/service tests

IMPORTANT: Write ONLY tests that will PASS with the current codebase.
PROMPT,
            verify: null,
            skippable: true,
            timeout: 300,
        );

        // Step 4: Run tests
        $this->steps->runAi(
            name: '[3/3] Running and fixing tests',
            prompt: <<<PROMPT
Run the project tests. If any tests fail, fix them.
Run: npm test (or npx jest or npx vitest run)
If tests fail, analyze the output and fix either the test or the code.
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
