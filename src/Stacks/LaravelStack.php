<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;
use Tessera\Installer\StepRunner;

/**
 * Full Laravel + Filament + Tessera stack.
 *
 * Uses StepRunner for AI-powered error recovery:
 * 1. Try the step
 * 2. Verify it worked
 * 3. If failed → AI tries to fix
 * 4. If AI can't → ask user, wait, verify
 */
final class LaravelStack implements StackInterface
{
    private StepRunner $steps;

    private string $fullPath;

    private AiTool $ai;

    /** @var array<string, mixed> */
    private array $requirements;

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
        return 'Websites, CMS, e-commerce, admin panels, CRUD applications, '
            . 'multi-language sites, blog platforms, booking systems. '
            . 'Best for: content websites, web shops, '
            . 'business applications, internal tools, dashboards. '
            . 'Stack: PHP 8.2+, Laravel 12, Filament 5, Livewire 4, Tailwind 4, MySQL/SQLite.';
    }

    public function preflight(): array
    {
        $missing = [];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $missing[] = 'PHP 8.2+ (found: ' . PHP_VERSION . ')';
        }

        $composer = Console::execSilent('composer --version');
        if ($composer['exit'] !== 0) {
            $missing[] = 'Composer (https://getcomposer.org)';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, AiTool $ai): bool
    {
        $this->fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $this->ai = $ai;
        $this->requirements = $requirements;
        $this->steps = new StepRunner($ai, $this->fullPath);

        // Step 1: Create Laravel project (runs in parent dir since project doesn't exist yet)
        $parentRunner = new StepRunner($ai, getcwd());
        $result = $parentRunner->runCommand(
            name: 'Create Laravel project',
            command: "composer create-project laravel/laravel {$directory} --prefer-dist --no-interaction",
            verify: fn (): ?string => is_file($this->fullPath . '/artisan') ? null : 'artisan file not found',
            fixHint: "Run: composer create-project laravel/laravel {$directory} --prefer-dist",
        );

        if (! $result) {
            return false;
        }

        // Step 2: Install core packages
        if (! $this->installCorePackages()) {
            return false;
        }

        // Step 3: Install dev packages
        $this->installDevPackages();

        // Step 4: Filament setup
        if (! $this->setupFilament()) {
            return false;
        }

        // Step 5: Publish package configs
        $this->publishConfigs();

        // Step 6: Create Tessera directory structure
        if (! $this->createStructure()) {
            return false;
        }

        // Step 7: AI builds models, views, content
        if (! $this->aiScaffold()) {
            return false;
        }

        // Summary
        $this->steps->printSummary();

        return true;
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;

        Console::spinner('Running migrations...');
        Console::exec('php artisan migrate --force', $fullPath);

        Console::spinner('Building assets...');
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
                'Site' => 'http://localhost:8000',
                'Admin' => 'http://localhost:8000/admin',
                'Login' => 'admin@tessera.test / password',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Private step methods
    // -------------------------------------------------------------------------

    private function installCorePackages(): bool
    {
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

        if ($this->requirements['needs_shop'] ?? false) {
            $packages[] = 'laravel/scout';
            $packages[] = 'meilisearch/meilisearch-php';
            $packages[] = 'barryvdh/laravel-dompdf';
            $packages[] = 'maatwebsite/excel';
        }

        return $this->steps->installPackages('Install core packages', $packages);
    }

    private function installDevPackages(): bool
    {
        return $this->steps->installPackages(
            'Install dev tools',
            ['laravel/boost', 'laravel/pint', 'laravel/telescope', 'larastan/larastan'],
            dev: true,
        );
    }

    private function setupFilament(): bool
    {
        // Install Filament panels
        $result = $this->steps->runCommand(
            name: 'Filament panel setup',
            command: 'php artisan filament:install --panels --no-interaction',
            verify: function (): ?string {
                // Check AdminPanelProvider exists
                $path = $this->fullPath . '/app/Providers/Filament/AdminPanelProvider.php';

                return is_file($path) ? null : 'AdminPanelProvider not created';
            },
            fixHint: 'Run: php artisan filament:install --panels',
        );

        if (! $result) {
            return false;
        }

        // Create admin user via AI
        $this->steps->runAi(
            name: 'Create admin user',
            prompt: <<<'PROMPT'
Create a Filament admin user with these credentials:
- Name: Admin
- Email: admin@tessera.test
- Password: password

Use the User model to create it. Run:
php artisan tinker --execute="App\Models\User::create(['name'=>'Admin','email'=>'admin@tessera.test','password'=>bcrypt('password')])"

If User model doesn't have 'name' field, check the migration and adapt.
PROMPT,
            verify: function (): ?string {
                $result = Console::execSilent(
                    'php artisan tinker --execute="echo App\\Models\\User::where(\'email\',\'admin@tessera.test\')->exists() ? \'YES\' : \'NO\';"',
                    $this->fullPath,
                );

                return str_contains($result['output'], 'YES') ? null : 'Admin user not created';
            },
            skippable: true,
        );

        // Configure CuratorPlugin in AdminPanelProvider via AI
        $this->steps->runAi(
            name: 'Configure Filament plugins',
            prompt: <<<'PROMPT'
In app/Providers/Filament/AdminPanelProvider.php, add CuratorPlugin to the panel.

Add this import at the top:
use Awcodes\Curator\CuratorPlugin;

And add ->plugin(CuratorPlugin::make()) to the panel configuration chain.

Also set the panel timezone to Europe/Zagreb and locale to hr.
PROMPT,
            verify: function (): ?string {
                $content = @file_get_contents($this->fullPath . '/app/Providers/Filament/AdminPanelProvider.php');
                if ($content === false) {
                    return 'AdminPanelProvider not found';
                }

                return str_contains($content, 'CuratorPlugin') ? null : 'CuratorPlugin not registered';
            },
            skippable: true,
        );

        return true;
    }

    private function publishConfigs(): bool
    {
        $publishes = [
            'spatie/laravel-permission' => [
                'command' => 'php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider"',
                'check' => 'config/permission.php',
            ],
            'spatie/laravel-medialibrary' => [
                'command' => 'php artisan vendor:publish --provider="Spatie\\MediaLibrary\\MediaLibraryServiceProvider" --tag="medialibrary-migrations"',
                'check' => null,
            ],
            'spatie/laravel-honeypot' => [
                'command' => 'php artisan vendor:publish --provider="Spatie\\Honeypot\\HoneypotServiceProvider" --tag="honeypot-config"',
                'check' => 'config/honeypot.php',
            ],
            'filament-curator' => [
                'command' => 'php artisan vendor:publish --tag="curator-migrations"',
                'check' => null,
            ],
        ];

        foreach ($publishes as $name => $config) {
            $this->steps->runCommand(
                name: "Publish: {$name}",
                command: $config['command'] . ' --no-interaction',
                verify: $config['check']
                    ? fn (): ?string => is_file($this->fullPath . '/' . $config['check']) ? null : "{$config['check']} not found"
                    : null,
                skippable: true,
                fixHint: 'Run: ' . $config['command'],
            );
        }

        return true;
    }

    private function createStructure(): bool
    {
        return $this->steps->run(
            name: 'Create Tessera directory structure',
            execute: function (): bool {
                $dirs = [
                    'app/Core/Models',
                    'app/Core/Services',
                    'app/Core/Http',
                    'app/Core/Ai',
                    'app/Modules',
                    'resources/views/themes/default/layouts',
                    'resources/views/themes/default/blocks',
                    'resources/views/themes/default/partials',
                    '.ai',
                ];

                foreach ($dirs as $dir) {
                    $path = $this->fullPath . '/' . $dir;
                    if (! is_dir($path)) {
                        if (! @mkdir($path, 0755, true)) {
                            return false;
                        }
                    }
                }

                return true;
            },
            verify: fn (): ?string => is_dir($this->fullPath . '/app/Core/Models') ? null : 'Core directories not created',
        );
    }

    private function aiScaffold(): bool
    {
        $desc = $this->requirements['description'] ?? 'Web project';
        $langs = implode(', ', $this->requirements['languages'] ?? ['en']);
        $shop = ($this->requirements['needs_shop'] ?? false) ? 'YES' : 'NO';
        $needsFrontend = ($this->requirements['needs_frontend'] ?? true) ? 'YES' : 'NO';
        $designStyle = $this->requirements['design_style'] ?? 'modern, clean';
        $designColors = $this->requirements['design_colors'] ?? 'use appropriate colors for the business type';

        // Step A: Models, migrations, services
        $this->steps->runAi(
            name: 'AI: Core models & migrations',
            prompt: <<<PROMPT
You are a Tessera AI senior developer. The project is Laravel 12 with Filament 5.
You are working in the project root directory. The directory structure is already created.

PROJECT DESCRIPTION: {$desc}
LANGUAGES: {$langs}
E-COMMERCE: {$shop}

CREATE:
1. CORE MODELS in app/Core/Models/:
   - Page.php (title, slug, meta_title, meta_description, og_image, is_published, published_at)
   - Block.php (page_id, type, data JSON, order, is_visible)
   - Navigation.php (label, url, location, parent_id, order, is_active)

2. MIGRATIONS for all core models

3. SERVICES in app/Core/Services/:
   - PageRenderer.php — resolve page by slug, render with theme layout
   - BlockRegistry.php — maps block type to blade view path
   - ThemeManager.php — returns active theme name

4. HELPERS in app/Core/helpers.php:
   - curator_url() helper for media
   - module_active() helper

5. PageController in app/Core/Http/ — catch-all for /{slug?}

6. Register helpers autoload in composer.json

IMPORTANT: declare(strict_types=1), typed properties, return types everywhere.
Use SQLite as the default database.
PROMPT,
            verify: function (): ?string {
                if (! is_file($this->fullPath . '/app/Core/Models/Page.php')) {
                    return 'Page model not created';
                }
                if (! is_file($this->fullPath . '/app/Core/Services/PageRenderer.php')) {
                    return 'PageRenderer not created';
                }

                return null;
            },
            timeout: 600,
        );

        // Step B: Theme views
        $this->steps->runAi(
            name: 'AI: Theme & block views',
            prompt: <<<PROMPT
CONTINUE working on the Tessera project. Core models and services are already created.

PROJECT DESCRIPTION: {$desc}
GENERATE FRONTEND: {$needsFrontend}
DESIGN STYLE: {$designStyle}
DESIGN COLORS: {$designColors}

CREATE:
1. THEME in resources/views/themes/default/:
   - layouts/master.blade.php — HTML5, Tailwind 4, @vite, @livewireStyles/Scripts
   - partials/header.blade.php — sticky nav, mobile menu, logo
   - partials/footer.blade.php — simple footer with links, copyright

2. BLOCK VIEWS in resources/views/themes/default/blocks/:
   - hero.blade.php — large hero section with heading, subheading, CTA button, background
   - text.blade.php, text-image.blade.php — content blocks
   - feature-cards.blade.php — 3-4 cards with icons, titles, descriptions
   - cta-banner.blade.php — call-to-action banner
   - contact-form.blade.php — contact form with validation
   - faq-accordion.blade.php — collapsible FAQ items
   - gallery-masonry.blade.php — image gallery grid
   - testimonials.blade.php — customer testimonials/reviews

3. Routing in bootstrap/app.php — catch-all route for PageController (AFTER Filament routes)

4. config/platform.php — site_name, theme, etc.

DESIGN INSTRUCTIONS:
- Use Tailwind 4 utility classes for ALL styling
- Design style: {$designStyle}
- Color palette: {$designColors}
- Must be RESPONSIVE (mobile-first)
- Hero section must be visually striking with gradient or background color
- Cards should have hover effects (shadow, scale)
- Use proper spacing, typography hierarchy (text-4xl for headings, etc.)
- Navigation must have mobile hamburger menu (Alpine.js x-data)
- Footer with columns for links, contact info, social icons
- All blocks must look PROFESSIONAL — not like a template, like a real website
PROMPT,
            verify: function (): ?string {
                if (! is_file($this->fullPath . '/resources/views/themes/default/layouts/master.blade.php')) {
                    return 'Master layout not created';
                }

                return null;
            },
            skippable: true,
            timeout: 600,
        );

        // Step C: Filament resources
        $this->steps->runAi(
            name: 'AI: Filament admin resources',
            prompt: <<<PROMPT
CONTINUE working on the Tessera project. Models, theme, and views are already created.

CREATE:
1. FILAMENT RESOURCES:
   - PageResource — Builder field for blocks, SEO tab, publish toggle
   - NavigationResource — CRUD for navigation (header/footer groups)

2. Register CuratorPlugin in AdminPanelProvider (if not already done)

3. CLAUDE.md — Tessera conventions and instructions for AI

4. .ai/platform.md — brief architecture overview
5. .ai/conventions.md — coding conventions
6. .ai/blocks.md — block types documentation

IMPORTANT: PageResource must have a Builder field with block types.
PROMPT,
            verify: function (): ?string {
                // Check for any Resource file in Filament dir
                $dir = $this->fullPath . '/app/Filament/Resources';

                if (! is_dir($dir)) {
                    return 'Filament Resources directory not created';
                }

                $files = glob($dir . '/*.php');

                return ! empty($files) ? null : 'No Filament resources created';
            },
            skippable: true,
            timeout: 600,
        );

        // Step D: Content & pages
        $this->steps->runAi(
            name: 'AI: Pages & content',
            prompt: <<<PROMPT
CONTINUE working on the Tessera project. Everything is set up — models, views, admin.

PROJECT DESCRIPTION: {$desc}
LANGUAGES: {$langs}

CREATE:
1. SEEDER — DatabaseSeeder that creates:
   - Home page with blocks: hero (with compelling headline + CTA), feature-cards (3-4 key selling points), testimonials (2-3 reviews), cta-banner
   - About page with blocks: text-image (company story), feature-cards (team/values), gallery-masonry
   - FAQ page with blocks: hero (smaller), faq-accordion (6-8 realistic questions)
   - Contact page with blocks: contact-form, text (address/phone/email)
   - Header navigation for all pages
   - Footer navigation

2. Content must be REALISTIC for the project description — NO lorem ipsum.
   Write content in the project's primary language ({$langs}).
   Make it sound like a real business website — professional copywriting.

3. Run: php artisan migrate --force && php artisan db:seed --force

4. config/ai.php — AI configuration
PROMPT,
            verify: function (): ?string {
                $result = Console::execSilent(
                    'php artisan tinker --execute="echo App\\Core\\Models\\Page::count();"',
                    $this->fullPath,
                );

                $count = (int) trim($result['output']);

                return $count > 0 ? null : 'No pages created by seeder';
            },
            skippable: true,
            timeout: 600,
        );

        return true;
    }
}
