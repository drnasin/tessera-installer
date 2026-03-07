<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;

/**
 * Static site (HTML + Tailwind + Alpine.js).
 * For simple landing pages that don't need a backend.
 * STATUS: Fully functional.
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
        return 'Jednostavne landing stranice, portfolio stranice, event stranice, '
            . 'coming soon stranice — bez backend-a, bez baze. '
            . 'Najbolji izbor za: brze jednokratne stranice, kampanje, '
            . 'osobne portfolio stranice, event pozivnice. '
            . 'Stack: HTML5, Tailwind CSS 4, Alpine.js, Vite. Deploy: Netlify/Vercel/GitHub Pages.';
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

    public function scaffold(string $directory, array $requirements, AiTool $ai): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $desc = $requirements['description'] ?? 'Landing stranica';

        Console::spinner('AI is generating static site...');

        if (! @mkdir($fullPath, 0755, true) && ! is_dir($fullPath)) {
            Console::error("Could not create directory: {$fullPath}");

            return false;
        }

        $prompt = <<<PROMPT
Create a complete static website in the current directory.

DESCRIPTION: {$desc}

Create:
1. package.json with Vite + Tailwind CSS 4 + Alpine.js
2. vite.config.js
3. index.html — complete responsive page with real content (NOT lorem ipsum)
4. src/style.css — Tailwind imports
5. src/main.js — Alpine.js init
6. Additional HTML pages if needed (about.html, contact.html)
7. README.md with deploy instructions (Netlify/Vercel)
8. netlify.toml or vercel.json for automatic deploy

The page must be:
- Responsive (mobile-first)
- Semantic HTML5
- Accessible (WCAG AA)
- SEO optimized (meta tags, OG tags, structured data)
PROMPT;

        $response = $ai->execute($prompt, $fullPath, 300);

        if (! $response->success) {
            Console::error('AI scaffold failed: ' . $response->error);

            return false;
        }

        Console::line($response->output);
        Console::success('Static site generated');

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
}
