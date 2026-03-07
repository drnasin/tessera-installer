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

        Console::spinner('AI generira static site...');

        mkdir($fullPath, 0755, true);

        $prompt = <<<PROMPT
Kreiraj kompletnu static web stranicu u trenutnom direktoriju.

OPIS: {$desc}

Kreiraj:
1. package.json s Vite + Tailwind CSS 4 + Alpine.js
2. vite.config.js
3. index.html — kompletna responsive stranica s realnim sadrzajem (NE lorem ipsum)
4. src/style.css — Tailwind imports
5. src/main.js — Alpine.js init
6. Dodatne HTML stranice ako treba (about.html, contact.html)
7. README.md s uputama za deploy (Netlify/Vercel)
8. netlify.toml ili vercel.json za automatski deploy

Stranica mora biti:
- Responsive (mobile-first)
- Semanticki HTML5
- Pristupacna (WCAG AA)
- SEO optimizirana (meta tagovi, OG tagovi, structured data)
PROMPT;

        $response = $ai->execute($prompt, $fullPath, 300);

        if (! $response->success) {
            Console::error('AI scaffold nije uspio: ' . $response->error);

            return false;
        }

        Console::line($response->output);
        Console::success('Static site generiran');

        return true;
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;

        if (file_exists($fullPath . '/package.json')) {
            Console::spinner('Instaliram npm pakete...');
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
