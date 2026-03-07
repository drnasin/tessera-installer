<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;

/**
 * Full Laravel + Filament + Tessera stack.
 * This is the primary, fully autonomous stack.
 */
final class LaravelStack implements StackInterface
{
    public function name(): string
    {
        return 'laravel';
    }

    public function label(): string
    {
        return 'Laravel + Filament (Tessera CMS)';
    }

    public function description(): string
    {
        return 'Web stranice, CMS, e-commerce, admin paneli, CRUD aplikacije, '
            . 'multi-language sites, blog platforme, booking sustavi. '
            . 'Najbolji izbor za: sadrzajne web stranice, web shopove, '
            . 'poslovne aplikacije, interne alate, dashboarde. '
            . 'Stack: PHP 8.2+, Laravel 12, Filament 5, Livewire 4, Tailwind 4, MySQL/SQLite.';
    }

    public function preflight(): array
    {
        $missing = [];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $missing[] = 'PHP 8.2+ (imas: ' . PHP_VERSION . ')';
        }

        $composer = Console::execSilent('composer --version');
        if ($composer['exit'] !== 0) {
            $missing[] = 'Composer (https://getcomposer.org)';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, AiTool $ai): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;

        // Create Laravel project
        Console::spinner('Kreiram Laravel projekt...');

        $exit = Console::exec(
            "composer create-project laravel/laravel {$directory} --prefer-dist --no-interaction",
        );

        if ($exit !== 0) {
            Console::error('composer create-project nije uspio.');

            return false;
        }

        Console::success('Laravel projekt kreiran');

        // Install Tessera core packages
        Console::spinner('Instaliram Tessera pakete...');

        $packages = [
            'filament/filament',
            'awcodes/filament-curator',
            'spatie/laravel-permission',
            'spatie/laravel-translatable',
            'spatie/laravel-sluggable',
            'spatie/laravel-tags',
            'spatie/laravel-medialibrary',
            'spatie/laravel-sitemap',
            'spatie/laravel-honeypot',
            'intervention/image',
            'staudenmeir/laravel-adjacency-list',
        ];

        // Add shop-specific packages if needed
        if ($requirements['needs_shop'] ?? false) {
            $packages[] = 'laravel/scout';
            $packages[] = 'meilisearch/meilisearch-php';
            $packages[] = 'barryvdh/laravel-dompdf';
            $packages[] = 'maatwebsite/excel';
        }

        $packageList = implode(' ', $packages);

        $exit = Console::exec(
            "composer require {$packageList} --no-interaction",
            $fullPath,
        );

        if ($exit !== 0) {
            Console::warn('Neki paketi se mozda nisu instalirali. Nastavljam...');
        }

        Console::success('Core paketi instalirani');

        // Dev packages
        Console::spinner('Instaliram dev alate...');

        Console::exec(
            'composer require --dev laravel/boost laravel/pint laravel/telescope larastan/larastan --no-interaction',
            $fullPath,
        );

        Console::success('Dev alati instalirani');

        // Let AI scaffold everything
        Console::spinner('AI konfigurira i gradi projekt...');

        $prompt = $this->buildScaffoldPrompt($requirements);

        $response = $ai->execute($prompt, $fullPath, 600);

        if (! $response->success) {
            Console::error('AI scaffold nije uspio: ' . $response->error);
            Console::line('Projekt je kreiran u: ' . $fullPath);

            return false;
        }

        Console::line();
        Console::line($response->output);
        Console::success('AI scaffold zavrsen');

        return true;
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;

        Console::spinner('Pokrecem migracije...');
        Console::exec('php artisan migrate --force', $fullPath);

        Console::spinner('Buildamo assets...');
        $npm = Console::execSilent('npm --version');

        if ($npm['exit'] === 0) {
            Console::exec('npm install', $fullPath);
            Console::exec('npm run build', $fullPath);
        }

        Console::exec('php artisan config:cache', $fullPath);
        Console::exec('php artisan route:cache', $fullPath);
        Console::exec('php artisan view:cache', $fullPath);
        Console::exec('php artisan filament:cache-components', $fullPath);

        return true;
    }

    public function completionInfo(string $directory): array
    {
        return [
            'commands' => [
                "cd {$directory}",
                'php artisan serve',
            ],
            'urls' => [
                'Stranica' => 'http://localhost:8000',
                'Admin' => 'http://localhost:8000/admin',
                'Login' => 'admin@tessera.test / password',
            ],
        ];
    }

    private function buildScaffoldPrompt(array $requirements): string
    {
        $desc = $requirements['description'] ?? 'Web projekt';
        $langs = implode(', ', $requirements['languages'] ?? ['hr']);

        return <<<PROMPT
Ti si Tessera AI senior developer. Projekt je UPRAVO kreiran s Laravel 12.
Radis u project root direktoriju. Tvoj posao je KOMPLETNO postaviti projekt.

OPIS PROJEKTA: {$desc}
JEZICI: {$langs}

NAPRAVI SVE OVO REDOM:

1. FILAMENT SETUP
   - php artisan filament:install --panels
   - Kreiraj admin usera: admin@tessera.test / password
   - Registriraj CuratorPlugin u AdminPanelProvider

2. DIREKTORIJ STRUKTURA — Tessera CMS struktura:
   - app/Core/Models/ (Page, Block, Navigation, Setting)
   - app/Core/Services/ (PageRenderer, BlockRegistry, ThemeManager)
   - resources/views/themes/default/ (layouts, blocks, partials)
   - .ai/ direktorij s dokumentacijom

3. MIGRACIJE — pages, blocks, navigations, settings + modul tablice

4. MODELI — fillable, casts, relacije, scopes

5. TEMA — default tema: master layout, header, footer, block views (Tailwind 4)

6. PAGE CONTROLLER + ROUTING — catch-all za slug-based routing

7. STRANICE I SADRZAJ — realan sadrzaj (NE lorem ipsum)

8. NAVIGACIJA — header nav za sve stranice

9. FILAMENT RESOURCES — PageResource s Builder poljem za blokove

10. KONFIGURACIJA — config/platform.php, config/ai.php, .env

11. CLAUDE.md — Tessera konvencije

12. NPM — npm install, Tailwind 4 config, npm run build

VAZNO: declare(strict_types=1), typed properties, return types svugdje.
PROMPT;
    }
}
