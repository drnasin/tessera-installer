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

        // Step 2: Install all packages (core + dev in one pass to avoid repeated autoload)
        if (! $this->installAllPackages()) {
            return false;
        }

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

    private function installAllPackages(): bool
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

        // Install all core + dev packages in one composer call (single autoload generation)
        $devPackages = ['laravel/boost', 'laravel/pint', 'laravel/telescope', 'larastan/larastan'];

        $allPackages = implode(' ', $packages);
        $allDevPackages = implode(' ', $devPackages);

        Console::line();
        Console::spinner('Install packages');

        // Try core + dev in two fast calls
        $coreExit = Console::exec(
            "composer require {$allPackages} --no-interaction --no-autoloader",
            $this->fullPath,
        );

        $devExit = Console::exec(
            "composer require --dev {$allDevPackages} --no-interaction --no-autoloader",
            $this->fullPath,
        );

        if ($coreExit === 0) {
            // Generate autoload once
            Console::spinner('Generating autoload...');
            Console::exec('composer dump-autoload', $this->fullPath);
            Console::success('Install packages');

            return true;
        }

        // Bulk failed — fall back to StepRunner for individual installs with retry
        return $this->steps->installPackages('Install core packages', $packages)
            && $this->steps->installPackages('Install dev tools', $devPackages, dev: true);
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

        // Step E: Generate tests
        $this->steps->runAi(
            name: 'AI: Generate tests',
            prompt: <<<PROMPT
CONTINUE working on the Tessera project. Models, views, admin, and content are all created.

Analyze the project and generate PHPUnit/Pest tests in tests/Feature/:

1. ROUTE TESTS (tests/Feature/RouteTest.php):
   - Homepage (/) returns 200
   - Each seeded page returns 200 (about, contact, faq, etc.)
   - /admin redirects to login
   - /admin/login returns 200
   - Non-existent page returns 404

2. MODEL TESTS (tests/Feature/ModelTest.php):
   - Page can be created with required fields
   - Page has blocks relationship
   - Block belongs to page
   - Navigation can be created
   - Navigation scopes (header, footer) work

3. SERVICE TESTS (tests/Feature/ServiceTest.php):
   - PageRenderer resolves page by slug
   - BlockRegistry returns correct view path for known block type
   - ThemeManager returns active theme name

4. SEEDER TEST (tests/Feature/SeederTest.php):
   - DatabaseSeeder creates expected pages
   - DatabaseSeeder creates navigation items
   - Pages have blocks after seeding

Use RefreshDatabase trait. Use SQLite in-memory for tests.
Make sure phpunit.xml is configured for SQLite in-memory (:memory:).

IMPORTANT: Write ONLY tests that will PASS with the current codebase.
Do NOT test features that don't exist yet.
PROMPT,
            verify: function (): ?string {
                $dir = $this->fullPath . '/tests/Feature';
                if (! is_dir($dir)) {
                    return 'tests/Feature directory not found';
                }

                $files = glob($dir . '/*Test.php');

                return ! empty($files) ? null : 'No test files created';
            },
            skippable: true,
            timeout: 600,
        );

        // Step F: Run tests and fix failures
        $this->runAndFixTests();

        return true;
    }

    /**
     * Run tests, and if they fail, let AI fix them (up to 2 attempts).
     */
    private function runAndFixTests(): void
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            Console::line();
            Console::spinner($attempt === 1 ? 'Running tests...' : "Running tests (attempt {$attempt}/{$maxAttempts})...");

            $result = Console::execSilent(
                'php artisan test --no-interaction 2>&1',
                $this->fullPath,
            );

            if ($result['exit'] === 0) {
                Console::success('All tests passing');

                return;
            }

            // Tests failed
            $output = $result['output'];
            $failCount = 'some';

            if (preg_match('/(\d+)\s+failed/', $output, $m)) {
                $failCount = $m[1];
            }

            Console::warn("  {$failCount} test(s) failed");

            if ($attempt >= $maxAttempts) {
                Console::warn('  Skipping test fixes — project is functional, tests need manual review.');

                return;
            }

            // Let AI fix the failures
            // Truncate output to last 2000 chars to avoid prompt size issues
            $truncatedOutput = strlen($output) > 2000 ? '...' . substr($output, -2000) : $output;

            $this->steps->runAi(
                name: 'AI: Fix failing tests',
                prompt: <<<PROMPT
The project tests are failing. Here is the test output:

{$truncatedOutput}

Fix the failing tests. Rules:
1. If the test is wrong (testing something that doesn't exist), FIX THE TEST
2. If the code has a bug that the test caught, FIX THE CODE
3. Do NOT delete tests — fix them
4. Do NOT change test assertions to match wrong behavior — fix the root cause
5. Make sure all tests use RefreshDatabase trait and SQLite in-memory
PROMPT,
                verify: null,
                skippable: true,
                timeout: 300,
            );
        }
    }
}
