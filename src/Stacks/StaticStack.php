<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;
use Tessera\Installer\Memory;
use Tessera\Installer\StepRunner;
use Tessera\Installer\SystemInfo;

/**
 * Static site (HTML + Tailwind + Alpine.js).
 * For simple landing pages that don't need a backend.
 */
final class StaticStack implements StackInterface
{
    private StepRunner $steps;

    private string $fullPath;

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
            . 'coming soon pages — no backend, no database. '
            . 'Best for: quick one-off pages, campaigns, '
            . 'personal portfolio sites, event invitations. '
            . 'Stack: HTML5, Tailwind CSS (latest), Alpine.js, Vite. Deploy: Netlify/Vercel/GitHub Pages.';
    }

    public function preflight(): array
    {
        $missing = [];

        $npm = Console::execSilent('npm --version');
        if ($npm['exit'] !== 0) {
            $missing[] = 'Node.js + npm (https://nodejs.org)';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, AiTool $ai, SystemInfo $system, Memory $memory): bool
    {
        $this->fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $this->steps = new StepRunner($ai, $this->fullPath);

        $memory->init($directory, 'static', $requirements, $system->buildAiContext());

        $desc = $requirements['description'] ?? 'Landing page';
        $designStyle = $requirements['design_style'] ?? 'modern, clean';
        $designColors = $requirements['design_colors'] ?? 'use appropriate colors for the business type';
        $needsFrontend = ($requirements['needs_frontend'] ?? true) ? 'YES' : 'NO';
        $langs = implode(', ', $requirements['languages'] ?? ['en']);
        $nodeVersion = $this->detectVersions();
        $systemContext = $system->buildAiContext();

        Console::line();
        Console::bold('Building your site — this takes about 2-3 minutes.');
        Console::line();

        if (! @mkdir($this->fullPath, 0755, true) && ! is_dir($this->fullPath)) {
            Console::error("Could not create directory: {$this->fullPath}");

            return false;
        }

        // Step 1: AI scaffold
        $this->steps->runAi(
            name: '[1/2] Creating website',
            prompt: <<<PROMPT
Create a complete static website in the current directory.

{$systemContext}

RUNTIME: {$nodeVersion}
DESCRIPTION: {$desc}
LANGUAGES: {$langs}
DESIGN STYLE: {$designStyle}
DESIGN COLORS: {$designColors}

Create:
1. package.json with Vite + Tailwind CSS (latest) + Alpine.js
2. vite.config.js
3. index.html — complete responsive page with real content (NOT lorem ipsum)
4. src/style.css — Tailwind imports
5. src/main.js — Alpine.js init
6. Additional HTML pages if needed (about.html, contact.html)
7. README.md with deploy instructions (Netlify/Vercel)
8. netlify.toml or vercel.json for automatic deploy

DESIGN INSTRUCTIONS:
- Design style: {$designStyle}
- Color palette: {$designColors}
- Content language: {$langs}
- Must be RESPONSIVE (mobile-first)
- Semantic HTML5
- Accessible (WCAG AA)
- SEO optimized (meta tags, OG tags, structured data)
- Hero section must be visually striking
- Cards with hover effects
- Mobile hamburger menu (Alpine.js)
- All content must be REALISTIC for the project description — NO lorem ipsum
PROMPT,
            verify: function (): ?string {
                if (is_file($this->fullPath . '/index.html')) {
                    return null;
                }
                if (is_file($this->fullPath . '/package.json')) {
                    return null;
                }

                return 'No index.html or package.json created';
            },
            timeout: 300,
        );

        // Step 2: Validate HTML
        $this->steps->runAi(
            name: '[2/2] Validating and polishing',
            prompt: <<<PROMPT
Review the generated static site. Check:
1. All HTML files are valid and complete
2. All links between pages work
3. Meta tags are present (title, description, OG)
4. Mobile responsiveness is implemented
5. Fix any issues found
PROMPT,
            verify: null,
            skippable: true,
            timeout: 120,
        );

        $this->steps->printSummary();

        return true;
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;

        if (file_exists($fullPath . '/package.json')) {
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
                'Dev' => 'http://localhost:5173',
                'Build' => 'npm run build',
            ],
        ];
    }

    private function detectVersions(): string
    {
        $node = Console::execSilent('node --version');
        if ($node['exit'] === 0) {
            return 'Node.js ' . trim($node['output']);
        }

        return 'Node.js (latest)';
    }
}
