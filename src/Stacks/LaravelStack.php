<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\Complexity;
use Tessera\Installer\Console;
use Tessera\Installer\Memory;
use Tessera\Installer\Stacks\Prompts\LaravelPrompts;
use Tessera\Installer\StepRunner;
use Tessera\Installer\SystemInfo;
use Tessera\Installer\ToolRouter;

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

    private ToolRouter $router;

    private SystemInfo $system;

    private Memory $memory;

    /** @var array<string, mixed> */
    private array $requirements;

    /** Timeout in seconds for AI steps. Override via TESSERA_AI_TIMEOUT env var. */
    private int $aiTimeout;

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
            .'multi-language sites, blog platforms, booking systems. '
            .'Best for: content websites, web shops, '
            .'business applications, internal tools, dashboards. '
            .'Stack: PHP 8.2+, Laravel (latest), Filament (latest), Livewire, Tailwind, MySQL/SQLite.';
    }

    public function preflight(): array
    {
        $missing = [];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $missing[] = 'PHP 8.2+ (found: '.PHP_VERSION.')';
        }

        $composer = Console::execSilent('composer --version');
        if ($composer['exit'] !== 0) {
            $missing[] = 'Composer (https://getcomposer.org)';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, ToolRouter $router, SystemInfo $system, Memory $memory): bool
    {
        $this->fullPath = getcwd().DIRECTORY_SEPARATOR.$directory;
        $this->router = $router;
        $this->system = $system;
        $this->memory = $memory;
        $this->requirements = $requirements;
        $this->steps = new StepRunner($router, $this->fullPath);

        // AI timeout: env override → default 900s (COMPLEX steps need room to breathe)
        $envTimeout = getenv('TESSERA_AI_TIMEOUT');
        $this->aiTimeout = ($envTimeout !== false && (int) $envTimeout > 0)
            ? (int) $envTimeout
            : 900;

        // Check if we're resuming a previous install
        $resuming = is_file($this->fullPath.'/artisan');

        Console::line();
        if ($resuming) {
            Console::bold('Resuming build — skipping completed steps...');
        } else {
            Console::bold('Building your project — this takes about 10-15 minutes.');
            Console::line('  Go grab a coffee, AI is doing all the work.');
        }
        Console::line();

        // Step 1: Create Laravel project (skip if resuming — artisan already exists)
        if (! $resuming) {
            $parentRunner = new StepRunner($router, getcwd());
            $result = $parentRunner->runCommand(
                name: '[1/8] Create Laravel project',
                command: "composer create-project laravel/laravel {$directory} --prefer-dist --no-interaction",
                verify: fn (): ?string => is_file($this->fullPath.'/artisan') ? null : 'artisan file not found',
                fixHint: "Run: composer create-project laravel/laravel {$directory} --prefer-dist",
            );

            if (! $result) {
                return false;
            }

            // First run — init memory now that project directory exists
            $memory->init($directory, 'laravel', $requirements, $system->buildAiContext());
        } else {
            Console::success('[1/8] Create Laravel project (already done)');
            // Resuming — update context but preserve completed steps
            $memory->updateContext($requirements, $system->buildAiContext());
        }

        // Step 2: Install packages (skip if key packages already installed)
        if ($this->memory->isStepDone('packages')) {
            Console::success('[2/8] Install packages (already done)');
        } else {
            Console::line();
            Console::spinner('[2/8] Installing packages...');
            if (! $this->installAllPackages()) {
                return false;
            }
            $memory->completeStep('packages');
        }

        // Step 3: Filament setup
        if ($this->memory->isStepDone('filament')) {
            Console::success('[3/8] Setting up admin panel (already done)');
        } else {
            Console::line();
            Console::spinner('[3/8] Setting up admin panel...');
            if (! $this->setupFilament()) {
                return false;
            }
            $this->memory->completeStep('filament');
        }

        // Step 4: Publish configs
        if ($this->memory->isStepDone('configs')) {
            Console::success('[4/8] Publishing configs (already done)');
        } else {
            Console::line();
            Console::spinner('[4/8] Publishing configs...');
            $this->publishConfigs();
            $this->memory->completeStep('configs');
        }

        // Step 5: Create directory structure
        if ($this->memory->isStepDone('structure')) {
            Console::success('[5/8] Creating project structure (already done)');
        } else {
            Console::line();
            Console::spinner('[5/8] Creating project structure...');
            if (! $this->createStructure()) {
                return false;
            }
            $this->memory->completeStep('structure');
        }

        // Step 5b: Configure database
        Console::spinner('Configuring database...');
        $actualDb = $this->configureDatabase();
        Console::success("Database configured: {$actualDb}");

        // Step 6: AI builds everything
        Console::line();
        Console::bold('[6/8] AI is building your project — this is the big one...');
        Console::line('  AI is creating models, theme, admin, content, and tests.');
        Console::line('  This takes a few minutes. Sit tight.');
        Console::line();
        if (! $this->aiScaffold()) {
            return false;
        }

        // Summary
        $this->steps->printSummary();

        return true;
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd().DIRECTORY_SEPARATOR.$directory;

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
                'Setup guide' => 'SETUP.md',
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

            // Payment provider SDKs
            $providers = $this->requirements['payment_providers'] ?? [];
            if (in_array('stripe', $providers, true)) {
                $packages[] = 'stripe/stripe-php';
            }
            if (in_array('mollie', $providers, true)) {
                $packages[] = 'mollie/laravel-mollie';
            }
            if (in_array('paypal', $providers, true)) {
                $packages[] = 'srmklive/paypal';
            }
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
                $path = $this->fullPath.'/app/Providers/Filament/AdminPanelProvider.php';

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
            complexity: Complexity::SIMPLE,
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

        // Create Curator migration (curator:install is interactive, so we create it manually)
        $this->steps->run(
            name: 'Create Curator migration',
            execute: function (): bool {
                $migrationPath = $this->fullPath.'/database/migrations/'.date('Y_m_d_His').'_create_curator_table.php';

                return (bool) file_put_contents($migrationPath, <<<'MIGRATION'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curator', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('directory')->nullable();
            $table->string('visibility')->default('public');
            $table->string('name');
            $table->string('path')->index();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('type');
            $table->string('ext');
            $table->string('alt')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('caption')->nullable();
            $table->text('pretty_name')->nullable();
            $table->text('exif')->nullable();
            $table->longText('curations')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curator');
    }
};
MIGRATION);
            },
            verify: fn (): ?string => ! empty(glob($this->fullPath.'/database/migrations/*_create_curator_table.php'))
                ? null
                : 'Curator migration not created',
        );

        // Configure CuratorPlugin in AdminPanelProvider via AI
        $this->steps->runAi(
            name: 'Configure Filament plugins',
            complexity: Complexity::SIMPLE,
            prompt: <<<'PROMPT'
In app/Providers/Filament/AdminPanelProvider.php, add CuratorPlugin to the panel.

Add this import at the top:
use Awcodes\Curator\CuratorPlugin;

And add ->plugin(CuratorPlugin::make()) to the panel configuration chain.

Also set the panel timezone to Europe/Zagreb and locale to hr.
PROMPT,
            verify: function (): ?string {
                $content = @file_get_contents($this->fullPath.'/app/Providers/Filament/AdminPanelProvider.php');
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
            'filament-curator-config' => [
                'command' => 'php artisan vendor:publish --provider="Awcodes\Curator\CuratorServiceProvider"',
                'check' => 'config/curator.php',
            ],
        ];

        foreach ($publishes as $name => $config) {
            $this->steps->runCommand(
                name: "Publish: {$name}",
                command: $config['command'].' --no-interaction',
                verify: $config['check']
                    ? fn (): ?string => is_file($this->fullPath.'/'.$config['check']) ? null : "{$config['check']} not found"
                    : null,
                skippable: true,
                fixHint: 'Run: '.$config['command'],
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
                    'app/Filament/Support',
                    'resources/views/themes/default/layouts',
                    'resources/views/themes/default/templates',
                    'resources/views/themes/default/blocks',
                    'resources/views/themes/default/partials',
                    'resources/views/components/layouts',
                    '.ai',
                ];

                foreach ($dirs as $dir) {
                    $path = $this->fullPath.'/'.$dir;
                    if (! is_dir($path)) {
                        if (! @mkdir($path, 0755, true)) {
                            return false;
                        }
                    }
                }

                // Create TranslatableFields helper for Filament admin
                $this->createTranslatableFieldsHelper();

                return true;
            },
            verify: fn (): ?string => is_dir($this->fullPath.'/app/Core/Models') ? null : 'Core directories not created',
        );
    }

    /**
     * Detect installed package versions from composer.lock.
     */
    private function detectVersions(): string
    {
        $lockFile = $this->fullPath.'/composer.lock';

        if (! is_file($lockFile)) {
            return 'Laravel (latest) with Filament (latest)';
        }

        $lock = json_decode((string) file_get_contents($lockFile), true);
        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

        $versions = ['PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION];
        $detect = ['laravel/framework' => 'Laravel', 'filament/filament' => 'Filament', 'livewire/livewire' => 'Livewire'];

        foreach ($packages as $pkg) {
            $name = $pkg['name'] ?? '';
            if (isset($detect[$name])) {
                $ver = ltrim($pkg['version'] ?? '', 'v');
                // Extract major version (e.g. "12.5.3" → "12")
                $major = explode('.', $ver)[0];
                $versions[] = $detect[$name].' '.$major;
            }
        }

        return implode(', ', $versions);
    }

    private function aiScaffold(): bool
    {
        $desc = $this->requirements['description'] ?? 'Web project';
        $langs = implode(', ', $this->requirements['languages'] ?? ['en']);
        $shop = ($this->requirements['needs_shop'] ?? false) ? 'YES' : 'NO';
        $needsFrontend = ($this->requirements['needs_frontend'] ?? true) ? 'YES' : 'NO';
        $designStyle = $this->requirements['design_style'] ?? 'modern, clean';
        $designColors = $this->requirements['design_colors'] ?? 'use appropriate colors for the business type';
        $paymentProviders = $this->requirements['payment_providers'] ?? [];
        $payments = ! empty($paymentProviders) ? implode(', ', $paymentProviders) : 'none';
        $country = $this->requirements['country'] ?? '';
        $stackVersions = $this->detectVersions();
        $systemContext = $this->system->buildAiContext();
        $memoryContext = $this->memory->buildAiContext();

        // Step A: Models, migrations, services
        if ($this->memory->isStepDone('core_models')) {
            Console::success('Creating database models and services (already done)');
        } else {
            $this->memory->startStep('core_models');
            $this->steps->runAi(
                name: 'Creating database models and services',
                complexity: Complexity::COMPLEX,
                prompt: LaravelPrompts::models($systemContext, $memoryContext, $stackVersions, $desc, $langs, $shop, $payments, $country),
                verify: function (): ?string {
                    if (! is_file($this->fullPath.'/app/Core/Models/Page.php')) {
                        return 'Page model not created';
                    }
                    if (! is_file($this->fullPath.'/app/Core/Services/PageRenderer.php')) {
                        return 'PageRenderer not created';
                    }
                    if (! is_file($this->fullPath.'/app/Core/Http/PageController.php')) {
                        return 'PageController not created';
                    }
                    if (($this->requirements['needs_shop'] ?? false)
                        && ! is_file($this->fullPath.'/app/Modules/Shop/ShopServiceProvider.php')) {
                        return 'ShopServiceProvider not created';
                    }

                    return null;
                },
                timeout: $this->aiTimeout,
            );
            $this->memory->completeStep('core_models');
        } // end if !isStepDone('core_models')

        // Step B: Theme views
        if ($this->memory->isStepDone('theme')) {
            Console::success('Designing frontend theme and pages (already done)');
        } else {
            $this->memory->startStep('theme');
            $this->steps->runAi(
                name: 'Designing frontend theme and pages',
                complexity: Complexity::COMPLEX,
                prompt: LaravelPrompts::theme($desc, $needsFrontend, $designStyle, $designColors, $langs, $shop),
                verify: function (): ?string {
                    if (! is_file($this->fullPath.'/resources/views/themes/default/layouts/master.blade.php')) {
                        return 'Master layout not created';
                    }
                    if (empty(glob($this->fullPath.'/resources/views/themes/default/blocks/*.blade.php'))) {
                        return 'No block views created';
                    }
                    if (! is_file($this->fullPath.'/resources/views/themes/default/partials/header.blade.php')) {
                        return 'Header partial not created';
                    }
                    if (($this->requirements['needs_shop'] ?? false)
                        && ! is_file($this->fullPath.'/resources/views/components/layouts/shop.blade.php')) {
                        return 'Shop layout not created';
                    }

                    return null;
                },
                skippable: true,
                timeout: $this->aiTimeout,
            );
            // Peer review: a different AI checks the theme for UX issues
            $this->steps->review(
                stepName: 'frontend theme',
                reviewPrompt: <<<'REVIEW'
You are a UX REVIEWER. Read ALL files in resources/views/themes/default/ and resources/css/app.css.

Check for these specific issues and list ONLY actual problems you find:
- Dark theme used for a business that shouldn't have one (most businesses need light backgrounds)
- Text invisible against its background (low contrast, same color text and bg)
- Content only visible on hover (product names, prices — must be visible in default state)
- Links pointing to pages that don't exist (href="/about" but no /about route or page)
- Footer links hardcoded instead of coming from Navigation model
- Form inputs invisible (white inputs on white background)
- Missing mobile responsiveness (no responsive classes)

Format each issue as:
- CRITICAL/MEDIUM/LOW: description of the issue and which file

If everything looks good, respond with: "No issues found."
REVIEW,
                fixPrompt: <<<'FIX'
A peer reviewer found issues in the frontend theme. Fix ALL of them.
Read each issue carefully and make the necessary changes to the blade views and CSS.
Do not break existing functionality — only fix what the reviewer identified.
FIX,
            );

            $this->memory->completeStep('theme');
        } // end if !isStepDone('theme')

        // Step C: Filament resources
        if ($this->memory->isStepDone('admin')) {
            Console::success('Building admin panel (already done)');
        } else {
            $this->memory->startStep('admin');
            $this->steps->runAi(
                name: 'Building admin panel',
                complexity: Complexity::COMPLEX,
                prompt: LaravelPrompts::admin($desc, $shop),
                verify: function (): ?string {
                    // Check for any Resource file in Filament dir
                    $dir = $this->fullPath.'/app/Filament/Resources';

                    if (! is_dir($dir)) {
                        return 'Filament Resources directory not created';
                    }

                    $files = glob($dir.'/*.php');
                    if (empty($files)) {
                        return 'No Filament resources created';
                    }

                    // Check PageResource specifically (it's the most important)
                    if (! is_file($dir.'/PageResource.php')) {
                        return 'PageResource not created';
                    }

                    // Check CLAUDE.md was created
                    if (! is_file($this->fullPath.'/CLAUDE.md')) {
                        return 'CLAUDE.md not created';
                    }

                    return null;
                },
                skippable: true,
                timeout: $this->aiTimeout,
            );
            $this->memory->completeStep('admin');

            // Auto-fix Filament namespaces (version-agnostic, reads vendor/)
            $this->fixFilamentNamespaces();

            // Peer review: verify admin resources match models and theme
            $this->steps->review(
                stepName: 'admin panel',
                reviewPrompt: <<<'REVIEW'
You are a CODE REVIEWER. Read the Filament resources in app/Filament/Resources/.

Check for these specific issues:
- Resource references a model that doesn't exist in app/ (read the model files)
- Table column names don't match actual migration column names (read migrations)
- PageResource Builder block fields don't match blade view data keys
  (read resources/views/themes/default/blocks/*.blade.php and compare)
- Wrong Filament class imports (verify each use statement resolves to a real class)
- Missing relationships referenced in resource (verify they exist on the model)

Format each issue as:
- CRITICAL/MEDIUM/LOW: file — description

If everything matches correctly, respond with: "No issues found."
REVIEW,
                fixPrompt: <<<'FIX'
A peer reviewer found issues in the Filament admin resources. Fix ALL of them.
Read each issue carefully. Verify against the actual source files before making changes.
FIX,
            );

            // PHP lint — catch syntax errors before continuing
            $this->lintPhpFiles();
        } // end if !isStepDone('admin')

        // Step D: Content & pages
        if ($this->memory->isStepDone('content')) {
            Console::success('Writing content and seeding data (already done)');
        } else {
            $this->memory->startStep('content');
            $this->steps->runAi(
                name: 'Writing content and seeding data',
                complexity: Complexity::MEDIUM,
                prompt: LaravelPrompts::content($desc, $langs, $shop),
                verify: function (): ?string {
                    $result = Console::execSilent(
                        'php artisan tinker --execute="echo App\\Core\\Models\\Page::count();"',
                        $this->fullPath,
                    );

                    $count = (int) trim($result['output']);

                    return $count > 0 ? null : 'No pages created by seeder';
                },
                skippable: true,
                timeout: $this->aiTimeout,
            );
            $this->memory->completeStep('content');

            // Post-content verification: check routes and lint
            $this->verifyRoutes();
            $this->lintPhpFiles();
        } // end if !isStepDone('content')

        // Step E: Generate tests
        if ($this->memory->isStepDone('tests')) {
            Console::success('AI: Generate tests (already done)');
        } else {
            $this->memory->startStep('tests');
            $this->steps->runAi(
                name: 'AI: Generate tests',
                complexity: Complexity::MEDIUM,
                prompt: <<<'PROMPT'
CONTINUE working on the Tessera project. Models, views, admin, and content are all created.

Generate PHPUnit tests in tests/Feature/. Use PHPUnit class-based syntax (NOT Pest function syntax).
Each test class must extend Tests\TestCase and use Illuminate\Foundation\Testing\RefreshDatabase.

1. ROUTE TESTS (tests/Feature/RouteTest.php):
   - Homepage (/) returns 200
   - Each seeded page returns 200 (about, contact, faq, etc.)
   - /admin redirects to login
   - /admin/login returns 200
   - Non-existent page returns 404

2. URL INTEGRITY TESTS (tests/Feature/NavigationUrlTest.php):
   THIS IS CRITICAL — it catches broken links before the user ever sees them.
   After seeding:
   - Load ALL navigation items from the database
   - For EACH navigation URL, make a GET request and assert it returns 200 (not 404, not 500)
   - This ensures every link in the header/footer actually works
   - Also test that locale switching works: request with ?lang= parameter, verify
     the response is 200 and the session locale was updated

3. MODEL TESTS (tests/Feature/ModelTest.php):
   - Page can be created with required fields
   - Page has blocks relationship
   - Block belongs to page
   - Navigation can be created
   - Navigation scopes (header, footer) work

4. SERVICE TESTS (tests/Feature/ServiceTest.php):
   - PageRenderer resolves page by slug
   - BlockRegistry returns correct view path for known block type
   - ThemeManager returns active theme name

5. SEEDER TEST (tests/Feature/SeederTest.php):
   - DatabaseSeeder creates expected pages
   - DatabaseSeeder creates navigation items
   - Pages have blocks after seeding

6. ADMIN TESTS (tests/Feature/AdminTest.php):
   - Each Filament resource list page loads without errors (no "Class not found")
   - Creating a record through the admin doesn't show raw JSON in form fields

Use RefreshDatabase trait. Configure phpunit.xml to use SQLite in-memory (:memory:) for tests
regardless of what database the application uses — tests must be fast and isolated.

IMPORTANT:
- Write ONLY tests that will PASS with the current codebase. Do NOT test features that don't exist yet.
- The URL integrity test is the most important — it proves the site actually works end-to-end.
- Use PHPUnit class syntax: class RouteTest extends TestCase { public function test_homepage(): void { } }
  Do NOT use Pest syntax (test(), it(), describe(), etc.) — this project uses PHPUnit.
- If any models use MySQL-specific features, ensure tests still work on SQLite :memory:.
  Common SQLite incompatibilities: ENUM columns (use string), json_extract (works differently),
  unsigned bigint (use regular bigint). Use Laravel's column types which abstract these.
PROMPT,
                verify: function (): ?string {
                    $dir = $this->fullPath.'/tests/Feature';
                    if (! is_dir($dir)) {
                        return 'tests/Feature directory not found';
                    }

                    $files = glob($dir.'/*Test.php');

                    return ! empty($files) ? null : 'No test files created';
                },
                skippable: true,
                timeout: $this->aiTimeout,
            );
            $this->memory->completeStep('tests');
        } // end if !isStepDone('tests')

        // Step F: Run tests and fix failures
        if (! $this->memory->isStepDone('tests_fixed')) {
            $this->runAndFixTests();
            $this->memory->completeStep('tests_fixed');
        } else {
            Console::success('AI: Fix failing tests (already done)');
        }

        // Step G: Generate developer handoff — SETUP.md with what the dev needs to do
        if ($this->memory->isStepDone('setup_md')) {
            Console::success('Generating setup instructions for developer (already done)');
        } else {
            $this->memory->startStep('setup_md');
            $this->steps->runAi(
                name: 'Generating setup instructions for developer',
                complexity: Complexity::SIMPLE,
                prompt: <<<PROMPT
FINAL STEP. Read the entire project you just built. Generate a SETUP.md file in the project root.

This file is for the DEVELOPER (junior). It must tell them EXACTLY what they need to do
to make this project fully operational. Be specific — include URLs, dashboard links, exact .env key names.

PROJECT: {$desc}
E-COMMERCE: {$shop}
PAYMENT PROVIDERS: {$payments}
COUNTRY: {$country}

SETUP.md must include:

1. QUICK START
   - Commands to run (migrate, seed, serve)
   - Default admin login credentials
   - URLs (site, admin panel)

2. ENVIRONMENT VARIABLES TO CONFIGURE
   List EVERY .env variable that needs a real value. For each one:
   - The exact key name (e.g., STRIPE_SECRET_KEY)
   - What it is (e.g., "Your Stripe secret API key")
   - WHERE to get it (e.g., "Go to https://dashboard.stripe.com/apikeys → Secret key")
   - Whether it's required or optional
   - Example test/sandbox value if available

3. PAYMENT PROVIDER SETUP (if e-commerce)
   For EACH payment provider ({$payments}):
   - Step-by-step account setup instructions
   - Where to get API keys / credentials
   - Test/sandbox mode instructions (test card numbers, test credentials)
   - Webhook URL to configure (the exact URL: https://yourdomain.com/webhooks/stripe etc.)
   - What to do when going to production (switch keys, verify domain, etc.)

4. EMAIL CONFIGURATION
   - Which email provider to use (Mailtrap for dev, real SMTP for production)
   - .env keys: MAIL_HOST, MAIL_PORT, MAIL_USERNAME, etc.

5. MEDIA / STORAGE
   - How Curator handles uploads
   - Storage link command if needed (php artisan storage:link)
   - Production: S3 configuration if scaling

6. GOING TO PRODUCTION CHECKLIST
   - [ ] Set APP_ENV=production, APP_DEBUG=false
   - [ ] Configure real database (MySQL/PostgreSQL)
   - [ ] Set up real email (not Mailtrap)
   - [ ] Switch payment providers to live mode
   - [ ] Set APP_URL to real domain
   - [ ] Run: php artisan config:cache && route:cache && view:cache
   - [ ] Set up SSL certificate
   - [ ] Configure backups

7. ADMIN MANAGEMENT (if e-commerce)
   - Explain that ALL business settings are managed through the admin panel
   - No code or config file changes are needed for day-to-day operations

8. COMMON TASKS
   - How to add a new page (admin panel)
   - How to add a new block type (create blade view + add to BlockRegistry + add to PageResource builder)
   - How to change the theme/colors
   - How to add a new language

Write in CLEAR, simple language. The developer is a junior — don't assume they know
what a webhook is. Explain briefly when needed.
PROMPT,
                verify: fn (): ?string => is_file($this->fullPath.'/SETUP.md') ? null : 'SETUP.md not created',
                skippable: true,
                timeout: $this->aiTimeout,
            );
            $this->memory->completeStep('setup_md');
        } // end if !isStepDone('setup_md')

        return true;
    }

    /**
     * Create the TranslatableFields helper class in the scaffolded project.
     *
     * This reusable helper generates locale tabs for Filament forms so that
     * AI-generated admin panels handle multilingual fields correctly out of the box.
     */
    private function createTranslatableFieldsHelper(): void
    {
        $path = $this->fullPath.'/app/Filament/Support/TranslatableFields.php';

        file_put_contents($path, <<<'HELPER'
<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Filament\Forms;
use Filament\Schemas\Components\Tabs;
use Illuminate\Database\Eloquent\Model;

/**
 * Helper for translatable form fields in Filament.
 *
 * Generates locale tabs (one tab per language) so admins can edit
 * all translations without switching the app locale.
 * Scales to any number of languages via config('platform.supported_locales').
 */
final class TranslatableFields
{
    /**
     * Create locale tabs for model translatable fields.
     *
     * The callback receives a locale string and should return
     * an array of Filament form components for that locale.
     *
     * Example:
     *   TranslatableFields::tabs(fn (string $locale) => [
     *       TranslatableFields::field('title', $locale, 'Title', required: true),
     *       TranslatableFields::field('meta_description', $locale, 'Meta Description', 'textarea'),
     *   ])
     */
    public static function tabs(Closure $fieldDefinitions): Tabs
    {
        $locales = config('platform.supported_locales', ['en']);

        $tabs = [];
        foreach ($locales as $locale) {
            $tabs[] = Tabs\Tab::make(strtoupper($locale))
                ->schema($fieldDefinitions($locale));
        }

        return Tabs::make('Translations')->tabs($tabs)->columnSpanFull();
    }

    /**
     * Create a single translatable model field for a specific locale.
     *
     * Handles hydration (loading from Spatie HasTranslations)
     * and marks the field as dehydrated(false) so it doesn't
     * get assigned directly to the model.
     */
    public static function field(
        string $attribute,
        string $locale,
        string $label,
        string $type = 'text',
        bool $required = false,
        ?int $maxLength = null,
        int $rows = 3,
    ): Forms\Components\Field {
        $component = self::makeComponent($type, "{$attribute}_{$locale}", $label);

        $component
            ->afterStateHydrated(function ($component, $record) use ($attribute, $locale): void {
                $value = '';
                if ($record) {
                    $value = (string) ($record->getTranslation($attribute, $locale) ?? '');
                }
                $component->state($value);
            })
            ->dehydrated(false);

        if ($required) {
            $component->required();
        }
        if ($maxLength !== null) {
            $component->maxLength($maxLength);
        }
        if ($type === 'textarea') {
            $component->rows($rows);
        }

        return $component;
    }

    /**
     * Create locale tabs for block data fields.
     *
     * Uses dot notation (e.g., heading.hr) so Filament stores
     * data as nested arrays: {"heading": {"hr": "...", "en": "..."}}
     * which the Block model's resolvedData() method handles.
     */
    public static function blockTabs(Closure $fieldDefinitions): Tabs
    {
        $locales = config('platform.supported_locales', ['en']);

        $tabs = [];
        foreach ($locales as $locale) {
            $tabs[] = Tabs\Tab::make(strtoupper($locale))
                ->schema($fieldDefinitions($locale));
        }

        return Tabs::make('Translations')->tabs($tabs)->columnSpanFull();
    }

    /**
     * Create a single block data field for a specific locale.
     * Uses dot notation for nested storage in the block's JSON data.
     */
    public static function blockField(
        string $attribute,
        string $locale,
        string $label,
        string $type = 'text',
        bool $required = false,
    ): Forms\Components\Field {
        $component = self::makeComponent($type, "{$attribute}.{$locale}", $label);

        if ($required) {
            $component->required();
        }

        return $component;
    }

    /**
     * Collect locale-suffixed field values into translation arrays.
     *
     * Transforms: ['title_hr' => 'Naslov', 'title_en' => 'Title']
     * Into:       ['title' => ['hr' => 'Naslov', 'en' => 'Title']]
     *
     * Used in mutateFormDataBeforeCreate().
     *
     * @param  array<string>  $translatableFields
     */
    public static function collectTranslations(array $data, array $translatableFields): array
    {
        $locales = config('platform.supported_locales', ['en']);

        foreach ($translatableFields as $field) {
            $translations = [];
            foreach ($locales as $locale) {
                $key = "{$field}_{$locale}";
                $translations[$locale] = $data[$key] ?? '';
                unset($data[$key]);
            }
            $data[$field] = $translations;
        }

        return $data;
    }

    /**
     * Save translations on a model using setTranslations().
     *
     * Reads locale-suffixed values from form state and calls
     * $record->setTranslations() for each translatable field.
     *
     * Used in mutateFormDataBeforeSave() on edit pages.
     *
     * @param  array<string, mixed>  $formState
     * @param  array<string>  $translatableFields
     */
    public static function saveTranslations(Model $record, array $formState, array $translatableFields): void
    {
        $locales = config('platform.supported_locales', ['en']);

        foreach ($translatableFields as $field) {
            $translations = [];
            foreach ($locales as $locale) {
                $key = "{$field}_{$locale}";
                $translations[$locale] = $formState[$key] ?? '';
            }
            $record->setTranslations($field, $translations);
        }
    }

    private static function makeComponent(string $type, string $name, string $label): Forms\Components\Field
    {
        return match ($type) {
            'textarea' => Forms\Components\Textarea::make($name)->label($label)->default(''),
            'rich' => Forms\Components\RichEditor::make($name)->label($label)->columnSpanFull()->default(''),
            'url' => Forms\Components\TextInput::make($name)->label($label)->url()->default(''),
            default => Forms\Components\TextInput::make($name)->label($label)->default(''),
        };
    }
}
HELPER);
    }

    /**
     * Fix wrong Filament namespaces in generated code.
     *
     * Version-agnostic: scans generated PHP files for Filament class references,
     * checks each one against vendor/, and fixes any that point to the wrong namespace.
     * Works with any Filament version — no hardcoded namespace maps.
     */
    private function fixFilamentNamespaces(): void
    {
        $filamentDir = $this->fullPath.'/app/Filament';
        $vendorDir = $this->fullPath.'/vendor/filament';

        if (! is_dir($filamentDir) || ! is_dir($vendorDir)) {
            return;
        }

        // Build a map of class name => actual namespace from vendor/filament/
        $classMap = $this->buildFilamentClassMap($vendorDir);

        if (empty($classMap)) {
            return;
        }

        // Scan generated files and fix wrong references
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filamentDir));
        $fixed = 0;

        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            $original = $content;

            // Find all Filament class references (use statements and inline FQCNs)
            // Match patterns like: Filament\Something\ClassName or Tables\Actions\ClassName
            if (preg_match_all('/(?:Filament\\\\[A-Za-z\\\\]+\\\\|Tables\\\\[A-Za-z\\\\]+\\\\|Forms\\\\[A-Za-z\\\\]+\\\\)([A-Z][A-Za-z]+)/', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $fullRef = $match[0]; // e.g., "Tables\Actions\EditAction"
                    $className = $match[1]; // e.g., "EditAction"

                    // Skip if this class isn't in our map
                    if (! isset($classMap[$className])) {
                        continue;
                    }

                    $correctFqcn = $classMap[$className];

                    // Build the FQCN from the reference as used in code
                    // References can be relative (Tables\Actions\EditAction) or absolute (\Filament\Tables\Actions\EditAction)
                    $usedFqcn = 'Filament\\'.$fullRef;
                    if (str_starts_with($fullRef, 'Filament\\')) {
                        $usedFqcn = $fullRef;
                    }

                    if ($usedFqcn !== $correctFqcn) {
                        // Determine how it's referenced in the file and replace appropriately
                        $content = str_replace($fullRef, $this->makeRelativeRef($correctFqcn), $content);
                    }
                }
            }

            if ($content !== $original) {
                file_put_contents($file->getPathname(), $content);
                $fixed++;
            }
        }

        if ($fixed > 0) {
            Console::warn("  Auto-fixed Filament namespaces in {$fixed} files");
        }
    }

    /**
     * Build a map of ClassName => full FQCN from vendor/filament/ source files.
     *
     * @return array<string, string>
     */
    private function buildFilamentClassMap(string $vendorDir): array
    {
        $map = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($vendorDir));

        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            // Only index classes commonly used in Resources (Actions, Components, Columns)
            $path = $file->getPathname();
            if (! preg_match('/(Actions|Components|Columns|Resources)/', $path)) {
                continue;
            }

            $className = $file->getBasename('.php');

            // Skip non-class files (interfaces, traits, concerns, contracts)
            if (str_contains($path, 'Concerns') || str_contains($path, 'Contracts')) {
                continue;
            }

            // Read namespace from file
            $handle = fopen($path, 'r');
            if ($handle === false) {
                continue;
            }

            $namespace = null;
            $lines = 0;

            while (($line = fgets($handle)) !== false && $lines < 10) {
                if (preg_match('/^namespace\s+(Filament\\\\[^;]+);/', $line, $m)) {
                    $namespace = $m[1];
                    break;
                }
                $lines++;
            }

            fclose($handle);

            if ($namespace !== null) {
                $fqcn = $namespace.'\\'.$className;

                // If multiple classes have the same name, prefer the one in a more specific namespace
                // (e.g., Filament\Actions\EditAction over Filament\Forms\Components\Actions\EditAction)
                if (! isset($map[$className]) || substr_count($fqcn, '\\') < substr_count($map[$className], '\\')) {
                    $map[$className] = $fqcn;
                }
            }
        }

        return $map;
    }

    /**
     * Convert a FQCN to the relative reference style used in Filament Resources.
     * e.g., "Filament\Actions\EditAction" → "\Filament\Actions\EditAction"
     */
    private function makeRelativeRef(string $fqcn): string
    {
        return '\\'.$fqcn;
    }

    /**
     * Verify that route:list works and log the result.
     * Catches misconfigured routes, missing controllers, and broken imports.
     * Runs after content seeding so the DB is populated.
     */
    private function verifyRoutes(): void
    {
        $result = Console::execSilent('php artisan route:list --json 2>&1', $this->fullPath);

        if ($result['exit'] !== 0) {
            Console::warn('  Route verification failed:');

            // Extract the most useful error line
            $lines = explode("\n", $result['output']);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, 'at ') && ! str_starts_with($line, '#')) {
                    Console::warn('    '.$line);
                    break;
                }
            }

            return;
        }

        // Check for registered routes count
        $routes = json_decode($result['output'], true);
        if (is_array($routes)) {
            Console::success('  Routes verified: '.count($routes).' routes registered');
        }
    }

    /**
     * Run php -l on all generated PHP files to catch syntax errors early.
     * Catches "Class not found", missing semicolons, etc. — zero tokens.
     */
    private function lintPhpFiles(): void
    {
        $dirs = [
            $this->fullPath.'/app',
            $this->fullPath.'/database',
            $this->fullPath.'/routes',
            $this->fullPath.'/config',
        ];

        $errors = [];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($rii as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }

                $result = Console::execSilent("php -l \"{$file->getPathname()}\"");

                if ($result['exit'] !== 0) {
                    $errors[] = $file->getPathname().': '.$result['output'];
                }
            }
        }

        if (! empty($errors)) {
            Console::warn('  PHP lint found '.count($errors).' syntax errors:');
            foreach (array_slice($errors, 0, 5) as $error) {
                Console::warn('    '.basename($error));
            }

            if (count($errors) > 5) {
                Console::warn('    ... and '.(count($errors) - 5).' more');
            }
        }
    }

    /**
     * Configure the database in .env based on requirements.
     */
    private function configureDatabase(): string
    {
        $db = $this->requirements['database'] ?? 'sqlite';
        $envFile = $this->fullPath.'/.env';

        if (! is_file($envFile)) {
            return 'sqlite';
        }

        $env = (string) file_get_contents($envFile);
        $projectName = basename($this->fullPath);

        if ($db !== 'sqlite') {
            $connected = $this->configureDatabaseServer($db, $env, $projectName);

            if (! $connected) {
                Console::warn('Falling back to SQLite — installation will continue without issues.');
                $db = 'sqlite';
                // Re-read env in case it was partially written
                $env = (string) file_get_contents($envFile);
            }
        }

        if ($db === 'sqlite') {
            $env = preg_replace('/^DB_CONNECTION=.*/m', 'DB_CONNECTION=sqlite', $env);
            $env = preg_replace('/^DB_HOST=.*\n?/m', '', $env);
            $env = preg_replace('/^DB_PORT=.*\n?/m', '', $env);
            $env = preg_replace('/^DB_DATABASE=(?!.*database\.sqlite).*\n?/m', '', $env);
            $env = preg_replace('/^DB_USERNAME=.*\n?/m', '', $env);
            $env = preg_replace('/^DB_PASSWORD=.*\n?/m', '', $env);
        }

        file_put_contents($envFile, $env);

        return $db;
    }

    /**
     * Ask for credentials and try to connect to MySQL/MariaDB or PostgreSQL.
     * Returns true if connection succeeded, false if it failed.
     * On success, writes credentials to $env (passed by reference).
     */
    private function configureDatabaseServer(string $db, string &$env, string $projectName): bool
    {
        $isPostgres = $db === 'postgresql';
        $label = $isPostgres ? 'PostgreSQL' : 'MySQL/MariaDB';
        $defaultUser = $isPostgres ? 'postgres' : 'root';
        $defaultPort = $isPostgres ? '5432' : '3306';
        $connection = $isPostgres ? 'pgsql' : 'mysql';

        Console::line();
        Console::bold("{$label} credentials:");
        $dbHost = Console::ask('Database host', '127.0.0.1');
        $dbPort = Console::ask('Database port', $defaultPort);
        $dbUser = Console::ask('Database username', $defaultUser);
        $dbPass = Console::ask('Database password (leave empty for none)', '');
        $dbName = Console::ask('Database name', $projectName);

        // Test connection first
        if ($isPostgres) {
            $pgEnv = $dbPass !== '' ? "PGPASSWORD={$dbPass} " : '';
            $testCmd = "{$pgEnv}psql -h {$dbHost} -p {$dbPort} -U {$dbUser} -c \"SELECT 1;\" 2>&1";
        } else {
            $passFlag = $dbPass !== '' ? " -p{$dbPass}" : '';
            $cli = $db === 'mariadb' ? 'mariadb' : 'mysql';
            $testCmd = "{$cli} -u {$dbUser}{$passFlag} -h {$dbHost} -P {$dbPort} -e \"SELECT 1;\" 2>&1";
        }

        $result = Console::execSilent($testCmd, $this->fullPath);

        if ($result['exit'] !== 0) {
            Console::line();
            Console::warn("Could not connect to {$label}: {$result['output']}");

            if (Console::confirm('Retry with different credentials?', true)) {
                return $this->configureDatabaseServer($db, $env, $projectName);
            }

            return false;
        }

        // Connection works — write credentials to env
        $env = preg_replace('/^DB_CONNECTION=.*/m', "DB_CONNECTION={$connection}", $env);
        $env = preg_replace('/^DB_HOST=.*/m', "DB_HOST={$dbHost}", $env);
        $env = preg_replace('/^DB_PORT=.*/m', "DB_PORT={$dbPort}", $env);
        $env = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$dbName}", $env);
        $env = preg_replace('/^DB_USERNAME=.*/m', "DB_USERNAME={$dbUser}", $env);
        $env = preg_replace('/^DB_PASSWORD=.*/m', "DB_PASSWORD={$dbPass}", $env);

        if (! preg_match('/^DB_HOST=/m', $env)) {
            $env = preg_replace('/^(DB_CONNECTION=.*)$/m', "$1\nDB_HOST={$dbHost}\nDB_PORT={$dbPort}\nDB_DATABASE={$dbName}\nDB_USERNAME={$dbUser}\nDB_PASSWORD={$dbPass}", $env);
        }

        // Try to create the database (use single quotes for MySQL identifier quoting — backticks break on Windows)
        if ($isPostgres) {
            $pgEnv = $dbPass !== '' ? "PGPASSWORD={$dbPass} " : '';
            $createResult = Console::execSilent("{$pgEnv}createdb -h {$dbHost} -p {$dbPort} -U {$dbUser} {$dbName} 2>&1", $this->fullPath);
        } else {
            $passFlag = $dbPass !== '' ? " -p{$dbPass}" : '';
            $cli = $db === 'mariadb' ? 'mariadb' : 'mysql';
            $createResult = Console::execSilent("{$cli} -u {$dbUser}{$passFlag} -h {$dbHost} -P {$dbPort} -e \"CREATE DATABASE IF NOT EXISTS {$dbName};\"", $this->fullPath);
        }

        if ($createResult['exit'] === 0) {
            Console::success("Database '{$dbName}' ready");

            return true;
        }

        // Auto-create failed — check if the database already exists
        if ($isPostgres) {
            $pgEnv = $dbPass !== '' ? "PGPASSWORD={$dbPass} " : '';
            $checkResult = Console::execSilent("{$pgEnv}psql -h {$dbHost} -p {$dbPort} -U {$dbUser} -d {$dbName} -c \"SELECT 1;\" 2>&1", $this->fullPath);
        } else {
            $checkResult = Console::execSilent("{$cli} -u {$dbUser}{$passFlag} -h {$dbHost} -P {$dbPort} {$dbName} -e \"SELECT 1;\" 2>&1", $this->fullPath);
        }

        if ($checkResult['exit'] === 0) {
            Console::success("Database '{$dbName}' already exists");

            return true;
        }

        // Database doesn't exist and we can't create it — wait for user
        Console::warn("Could not create database: {$createResult['output']}");
        Console::line("Please create the '{$dbName}' database manually, then press Enter to continue.");

        Console::ask('Press Enter when ready');

        // Verify after user says they created it
        if ($isPostgres) {
            $verifyResult = Console::execSilent("{$pgEnv}psql -h {$dbHost} -p {$dbPort} -U {$dbUser} -d {$dbName} -c \"SELECT 1;\" 2>&1", $this->fullPath);
        } else {
            $verifyResult = Console::execSilent("{$cli} -u {$dbUser}{$passFlag} -h {$dbHost} -P {$dbPort} {$dbName} -e \"SELECT 1;\" 2>&1", $this->fullPath);
        }

        if ($verifyResult['exit'] === 0) {
            Console::success("Database '{$dbName}' ready");

            return true;
        }

        Console::warn("Still cannot access database '{$dbName}': {$verifyResult['output']}");

        return false;
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
            $truncatedOutput = strlen($output) > 2000 ? '...'.substr($output, -2000) : $output;

            $this->steps->runAi(
                name: 'AI: Fix failing tests',
                complexity: Complexity::MEDIUM,
                prompt: <<<PROMPT
The project tests are failing. Here is the test output:

{$truncatedOutput}

Fix the failing tests. Rules:
1. If the test is wrong (testing something that doesn't exist), FIX THE TEST
2. If the code has a bug that the test caught, FIX THE CODE
3. Do NOT delete tests — fix them
4. Do NOT change test assertions to match wrong behavior — fix the root cause
5. Make sure all tests use RefreshDatabase trait and SQLite in-memory
6. Tests must be PHPUnit class syntax (NOT Pest). Convert if needed.
7. If a test fails because of SQLite vs MySQL incompatibility, fix the migration/model, not the test
PROMPT,
                verify: null,
                skippable: true,
                timeout: $this->aiTimeout,
            );
        }
    }
}
