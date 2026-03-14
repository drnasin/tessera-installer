<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\Complexity;
use Tessera\Installer\Console;
use Tessera\Installer\Memory;
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
                prompt: <<<PROMPT
You are a SENIOR Laravel developer building a Tessera CMS project from scratch.
Think carefully about what THIS specific project needs before writing any code.

{$systemContext}

{$memoryContext}

STACK: {$stackVersions}
You are in the project root directory. Directory structure already exists.

PROJECT: {$desc}
LANGUAGES: {$langs}
E-COMMERCE: {$shop}
PAYMENT PROVIDERS: {$payments}
COUNTRY: {$country}

STEP 1 — THINK (do not skip this):
Before creating anything, reason about what this project needs:
- What entities does this business have? (Pages are always needed. But does it also have products? Services? Rooms? Menu items? Events?)
- Does it need user accounts? (shop = yes, portfolio site = probably no)
- Does it need payments? If yes, which provider SDK is already installed?
- Does it need categories/tags? Search? Filtering?
- What relationships exist between entities?

GOLDEN RULE — NEVER HARDCODE BUSINESS VALUES:
All business-configurable values (prices, rates, thresholds, zones, labels, tax rates, etc.)
must be stored in the DATABASE and administrable via the Filament admin panel.
Config files (config/*.php) are for TECHNICAL settings only (DB, cache, queue, API keys).
The seeder populates sensible defaults so the app works out of the box, but every value
must be changeable by the admin without touching code or config files.
If you find yourself writing a number, price, percentage, or label in a config file or
a hardcoded array — STOP. That belongs in a database table with an admin CRUD.

STEP 2 — ALWAYS CREATE (every Tessera project has these):
1. CORE MODELS in app/Core/Models/:
   - Page.php (title, slug, meta_title, meta_description, og_image, is_published, published_at)
     Use HasTranslations for: title, meta_title, meta_description
     slug is NOT translatable — it's a plain string column
   - Block.php (page_id, type, data JSON, order, is_visible)
     Block.data is a JSON column cast as 'array'. Each block type stores its own structure.
     For multilingual projects, store translations INSIDE each data field as locale-keyed arrays.
     The Block model must provide a method that resolves these locale-keyed arrays to the current
     locale's value, so that blade views receive plain strings instead of arrays.
     PageRenderer must call this resolution before passing blocks to views.
   - Navigation.php (label, url, location, parent_id, order, is_active)
     Use HasTranslations for: label, url

2. MIGRATIONS for all models (use timestamps, soft deletes where appropriate)

3. SERVICES in app/Core/Services/:
   - PageRenderer.php — resolves page by slug, loads blocks, renders with theme
     Must resolve block data translations before passing to views (see Block model above)
   - BlockRegistry.php — maps block type string to blade view path
   - ThemeManager.php — returns active theme name

4. HELPERS in app/Core/helpers.php:
   - curator_url(\$media): handles both numeric IDs (Curator) and legacy URL strings
   - module_active(string \$module): checks if a module directory exists

5. PageController in app/Core/Http/ — handles page rendering:
   - The show() method receives an optional slug from the catch-all route /{slug?}
   - When slug is null (user visits /), render the homepage DIRECTLY — do NOT redirect to another URL.
     The homepage should be served at /, not at /pocetna or /home. No redirects.
   - To find the homepage: query the Page model for the first published page by sort order,
     or add an is_homepage boolean column to the pages table.
   - NEVER hardcode a slug like 'pocetna' or 'home' in the controller.
     The homepage slug must come from the database so the admin can change it.

6. Register helpers autoload in composer.json "files" array

7. LOCALE MIDDLEWARE — create a middleware that reads a locale query parameter,
   validates it against configured supported locales, persists the choice in the session,
   and sets the app locale. Register it in bootstrap/app.php as web middleware.

8. Set .env: APP_LOCALE and APP_FALLBACK_LOCALE to the project's PRIMARY language
   (must match the language of the content in the seeder — e.g., 'hr' for Croatian projects)

ROUTE ORDERING (CRITICAL — this causes 404 errors if wrong):
- The catch-all route /{slug?} MUST be registered LAST, after ALL other routes.
- In bootstrap/app.php, use the 'then:' callback to register the catch-all AFTER Filament:
  ->withRouting(web: __DIR__.'/../routes/web.php', then: function () {
      Route::middleware('web')->group(base_path('routes/shop.php')); // if e-commerce
      Route::get('/{slug?}', [PageController::class, 'show'])->where('slug', '.*');
  })
- NEVER use Route closures — always use [Controller::class, 'method'] syntax.
  Closures break `php artisan route:cache` which is required for production.
- If the shop has routes, load routes/shop.php BEFORE the catch-all but inside 'then:'.

VERIFICATION PRINCIPLES (apply throughout):
- Every class import must resolve to an actual class in the installed package version. If unsure, check the package source.
- Route closures that receive models via route model binding must type-hint their parameters.
- Livewire component registrations must use fully-qualified class names (not relative namespace paths that could conflict with the Livewire package namespace).
- All code must work correctly when the locale is switched — verify mentally by tracing the data flow.
- Every Livewire full-page component (one that returns a view with ->layout()) MUST have the
  layout declaration. Without it, Livewire renders the component without any HTML structure (no <html>, no <head>).
  Use either: `->layout('layouts.shop')` in render(), or `#[Layout('layouts.shop')]` attribute on the class.

STEP 3 — IF E-COMMERCE ({$shop}):
You are an E-COMMERCE EXPERT building a real online shop. Think like one.

Before writing any code, visit any professional e-commerce site mentally and ask:
"What can a CUSTOMER do?" — Browse, search, filter, view product details, add to cart,
manage their account, checkout, pay, track orders, reorder. Build the complete customer journey.
"What can a SHOP OWNER do?" — Manage everything through the admin panel. If they can't
change something without a developer, that's a bug.

Create a full shop module in app/Modules/Shop/.
YOU decide what models, enums, services, and components this business needs.
You're a senior developer — think it through. If you're unsure, err on the side of completeness.

PAYMENT PROVIDERS: {$payments}
Study how each provider works (redirect-based vs API-based) and implement accordingly.
API keys come from .env. Each gateway needs getConfigKeys() so the developer knows what to set up.

THREE ABSOLUTE RULES:
1. USER ACCOUNTS — Every e-commerce site has user accounts. Registration, login, profile,
   order history, saved addresses. Guest checkout must also work. Think about what features
   any professional online shop offers to its registered users. Build ALL of them.
2. NOTHING HARDCODED — ALL business values (prices, shipping zones, tax rates, thresholds,
   currency, zone names, postal code ranges) must be in the DATABASE, manageable via admin.
   config/shop.php is ONLY for technical settings (class mappings, .env key names).
   If you find yourself writing a price, rate, or label in PHP code or a config file — STOP.
   That value belongs in a database table with an admin CRUD and a seeder for defaults.
3. LIVEWIRE REGISTRATION — Components in app/Modules/Shop/Livewire/ are in a non-standard
   namespace. They MUST be manually registered in ShopServiceProvider::boot() using
   Livewire::component(). Without this, Livewire cannot find them.

IMPORTANT:
- declare(strict_types=1), typed properties, return types EVERYWHERE
- The database is already configured in .env — do NOT change it.
- Every Livewire component must have a corresponding blade view
- Database migrations must be compatible with BOTH SQLite and MySQL/PostgreSQL.
- If you're unsure whether a feature is needed — it probably is. Build it.
PROMPT,
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
                prompt: <<<PROMPT
CONTINUE working on the Tessera project. Core models and services are already created.
Read the Block model and BlockRegistry to understand the data flow.

YOUR ROLE: You are an EXPERIENCED UX DESIGNER and frontend developer.
You don't just write HTML — you design EXPERIENCES. Every decision you make must answer:
"Is this easy to use? Is this intuitive? Would a first-time visitor know what to do?"

VISUAL IDENTITY — THINK BEFORE YOU CODE:
Before writing any CSS or choosing colors, ask yourself:
- What does this business DO? What emotion should the website evoke?
- Who are the customers? What do they expect visually from this type of business?
- What do successful competitors' websites look like?

A bike shop needs energy, sport, outdoor vibes — bright colors, light backgrounds, big product photos.
A law firm needs trust and professionalism — navy/charcoal, serif headings, conservative layout.
A restaurant needs warmth and appetite — warm colors, food photography, inviting atmosphere.
A tech startup needs innovation — clean, minimal, bold accent color, lots of whitespace.

NEVER default to dark themes unless the business explicitly calls for it (gaming, nightclub, cinema).
Most businesses need LIGHT backgrounds — they make products, text, and images look better.
Dark themes hide content and feel heavy. Use them only when they serve the brand.

The admin panel (Filament) has its own design — the frontend must look COMPLETELY DIFFERENT.
The frontend is for CUSTOMERS, not admins. It should feel like a real website, not a dashboard.

COLOR PALETTE:
Define a custom Tailwind @theme in resources/css/app.css with colors appropriate for THIS business.
Pick a primary color that matches the brand personality, a neutral palette for backgrounds and text,
and an accent for CTAs. Use Tailwind's color scale (50-950) for each custom color.

UX PRINCIPLES you follow:
- HIERARCHY: The user's eye must be guided. Most important content first, clear visual weight.
  Every page has ONE primary action — make it obvious. Secondary actions are visually subordinate.
- AFFORDANCE: Interactive elements must LOOK interactive. Buttons look like buttons.
  Links are distinguishable from text. Clickable cards have hover states that invite interaction.
- FEEDBACK: Every user action gets immediate visual feedback. Button clicked → loading state.
  Form submitted → success/error message. Item added to cart → visual confirmation.
- CONSISTENCY: Same patterns everywhere. If "Add to cart" is a button with one style on one page,
  it uses the same style everywhere. Same spacing, same typography scale, same color meanings.
- ACCESSIBILITY: Sufficient color contrast (WCAG AA minimum). Focus states on interactive elements.
  Meaningful alt text on images. Logical heading hierarchy (h1 → h2 → h3, never skipping).
- MOBILE-FIRST: Design for phone screens first, enhance for desktop. Touch targets minimum 44px.
  No horizontal scrolling. Content readable without zooming.
- WHITESPACE: Generous spacing creates clarity. Cramped layouts feel cheap and overwhelming.
  Let content breathe. Sections need clear visual separation.
- NAVIGATION: The user must ALWAYS know where they are and how to get elsewhere.
  Clear active states, breadcrumbs where appropriate, consistent navigation placement.

PROJECT: {$desc}
GENERATE FRONTEND: {$needsFrontend}
DESIGN STYLE: {$designStyle}
DESIGN COLORS: {$designColors}
LANGUAGES: {$langs}

CRITICAL — HOW BLOCKS WORK (you MUST understand this):
Each block is a row in the database with type (string) and data (JSON).
The frontend reads: \$block->data['key']
The admin writes: Builder field with form fields per block type.
These MUST match. If a hero block has data keys "heading", "subheading", "cta_text", "cta_url",
then the blade view MUST use \$block->data['heading'], and the admin builder MUST have
TextInput::make('heading'), TextInput::make('subheading'), etc.

YOU decide what data keys each block type needs. Document them clearly.

CREATE:
1. THEME LAYOUT in resources/views/themes/default/:
   - layouts/master.blade.php — HTML5, Tailwind 4 (via @vite), @livewireStyles/@livewireScripts
   - partials/header.blade.php — sticky nav, mobile hamburger (Alpine.js x-data), logo, navigation items from DB
     If e-commerce, include the cart widget Livewire component. Each interactive element (cart, login, etc.)
     must appear exactly ONCE — don't duplicate functionality between a Livewire component and a static link.
   - partials/footer.blade.php — columns: links, contact info, social. Copyright year.
     CRITICAL: Footer links MUST come from the Navigation model (location='footer'), same as header.
     NEVER hardcode page slugs as href="/about" or href="/dostava" — these pages may not exist.
     Use Navigation::active()->location('footer') to load links from the database.
     The admin manages footer links through the Navigation resource — not through code.
   - templates/default.blade.php — @extends master, loops through blocks
   - templates/full-width.blade.php — same as default (create BOTH templates)

2. BLOCK VIEWS in resources/views/themes/default/blocks/:
   Each block view receives \$block (Block model). Access data via \$block->data['key'].
   PageRenderer already resolves translations, so \$block->data['heading'] returns a plain string.

   For EVERY block view you create, document the expected data keys as a comment at the top:
   {{-- Data keys: heading (string), subheading (string), cta_text (string), cta_url (string), background_image (int|string) --}}

   Create these blocks (adjust to what makes sense for THIS project):
   - hero.blade.php — heading, subheading, CTA button, background (image or gradient)
   - text.blade.php — rich text content block
   - text-image.blade.php — text + image side by side (with alignment option)
   - feature-cards.blade.php — array of cards, each with icon, title, description
   - cta-banner.blade.php — call-to-action with heading, text, button
   - contact-form.blade.php — Livewire contact form component
     IMPORTANT: This block embeds a Livewire component. You MUST create BOTH:
       a) The block blade view (resources/views/themes/default/blocks/contact-form.blade.php) — renders <livewire:contact-form />
       b) The Livewire component class (app/Livewire/ContactForm.php) + its blade view (resources/views/livewire/contact-form.blade.php)
     The Livewire component handles validation, submission, and success/error messages.
   - faq-accordion.blade.php — collapsible items (Alpine.js)
   - gallery-masonry.blade.php — grid of images from Curator
   - testimonials.blade.php — customer reviews with name, text, rating
   - newsletter.blade.php — email input + subscribe button
   - Add MORE block types if THIS project needs them (e.g., menu-list for restaurant, pricing-table for SaaS, team-members, etc.)

3. IF E-COMMERCE: Shop views (resources/views/shop/, resources/views/components/layouts/shop.blade.php)
   Shop pages must look like they belong to the same website. They must share the same
   header, footer, and visual style as the CMS pages. Do not create a separate design for shop.
   The shop layout (layouts/shop.blade.php) should @extend or @include the same master layout
   partials (header, footer) as the CMS theme — do NOT duplicate the header/footer HTML.
   Full-page Livewire shop components (CartPage, Checkout) MUST declare their layout:
     return view('shop.livewire.checkout', [...])->layout('layouts.shop');
   Without this, they render as raw HTML fragments without <html>, <head>, or any styling.

   USER ACCOUNT PAGES — If the project has user accounts (e-commerce always does), create
   ALL pages a registered user expects: authentication, profile, order history, etc.
   Think about what pages any professional online shop has for logged-in users. Build all of them.
   All account pages must use the same layout and visual style as the rest of the site.
   Header must be context-aware: show login/register for guests, account/logout for users.

4. Routing: catch-all /{slug?} in bootstrap/app.php (AFTER Filament routes, in 'then:' callback)
   NEVER use Route::get('/{slug?}', function() {}) — use controller syntax.
   Route closures cannot be cached by `php artisan route:cache`.

5. config/platform.php — site_name, default_theme, supported_locales, address, contact_phone, contact_email, social links

6. NULL-SAFE CONTENT: All blade views must handle null/empty data gracefully. Use null coalescing
   (\$block->data['key'] ?? '') for all data access. RichEditor/TipTap content MUST never be null —
   use empty string defaults. Blade {!! !!} output on null content can crash renderers.

7. MULTILINGUAL: All hardcoded text in templates must be locale-aware for all configured languages.
   Navigation labels and URLs come from the DB (HasTranslations) so they switch automatically.

DESIGN — You are designing a REAL website for a REAL business, not a code demo.
Before writing any Tailwind class, ask: "Would a client pay for this? Would users enjoy using it?"

Style: {$designStyle}
Colors: {$designColors}
Content language: {$langs}
Images: use curator_url(\$block->data['image']) helper for all media

SELF-CHECK — After creating each page/component, mentally render it and verify:
- Can I read ALL text against its background? (white text on light backgrounds = invisible)
- ALL text content (names, titles, prices, descriptions) must be visible WITHOUT hovering.
  Hover states add visual polish, but content must be readable in the DEFAULT state.
  If a product card shows the name only on hover — that is WRONG. Users can't hover on mobile.
- Do form inputs have visible borders/backgrounds? (white inputs on white = invisible)
- Is there enough contrast between sections? (alternating backgrounds help)
- Do interactive elements have hover/focus/active states?
- Does the page make sense on a 375px phone screen?
- Is there a clear visual hierarchy? Can I tell what's most important in 2 seconds?
- Are loading states handled? (wire:loading for Livewire, spinners for async actions)
- Are empty states handled? (empty cart, no search results, no orders yet)
- Are error states handled? (form validation, payment failure, 404 pages)

INTEGRATION CHECK — Your code does NOT exist in isolation. Other AI steps already created
middleware, routes, models, and services. Your views and components must match what already exists.
Before finishing, verify these connections:
- If you use a query parameter (?locale=, ?sort=, ?page=), READ the middleware or controller
  that handles it. Use the SAME parameter name — don't guess, check the actual source file.
- If you reference a route name (route('shop.cart')), run: grep -r "->name(" routes/ to verify it exists.
- If you call a Livewire component (<livewire:cart-widget />), verify the class exists in app/Livewire/.
- If you use a helper (curator_url(), active_page()), verify it exists in app/Core/helpers.php.
- If you use a config value (config('platform.supported_locales')), verify the config file has that key.
- NEVER hardcode page URLs as href="/about" or href="/dostava" in blade views.
  These pages may not exist. All navigation links must come from the Navigation model
  or use route() helper for named routes. The only acceptable hardcoded paths are
  well-known routes like /shop, /admin, or / (homepage).
Rule: NEVER assume another part of the codebase uses a specific name — READ IT and match it exactly.
If ANY answer is "no" — fix it before moving on.
PROMPT,
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
                prompt: <<<PROMPT
CONTINUE working on the Tessera project. Models, theme, and block views are already created.

CRITICAL TASK: Read EVERY block blade view you created in resources/views/themes/default/blocks/.
Look at the data key comments at the top of each file. The admin Builder MUST create form fields
that match EXACTLY those data keys.

Example: if hero.blade.php uses \$block->data['heading'], \$block->data['subheading'], \$block->data['cta_text']
then PageResource Builder must have:
Builder\Block::make('hero')->schema([
    TextInput::make('heading')->required(),
    TextInput::make('subheading'),
    TextInput::make('cta_text'),
    TextInput::make('cta_url'),
    CuratorPicker::make('background_image'),
])

CRITICAL — NAMESPACE VERIFICATION:
Filament changes namespaces between major versions. Your training data may be outdated.
DO NOT assume you know the correct namespace for ANY Filament class.

Before writing Filament code, you MUST verify namespaces by checking the installed source:

1. Run: find vendor/filament -name "EditAction.php" -type f
   This tells you the ACTUAL namespace. Use that, not what you remember.

2. For ANY Filament class you want to use (Section, EditAction, TextColumn, etc.):
   find vendor/filament -name "ClassName.php" -type f
   Then read the first few lines to get the correct namespace.

3. Check the installed Filament version:
   composer show filament/filament | head -5

This takes 10 seconds and prevents "Class not found" errors that break the entire admin.
NEVER guess a namespace — ALWAYS verify it against vendor/.

YOUR ROLE: You are a senior developer building the admin panel for this project.
Think about what the admin (site owner / business operator) needs to manage.
Ask yourself: "What does the admin need to see, create, edit, and monitor on a daily basis?"

Every model in the project that holds business data MUST have a Filament Resource so the admin
can manage it. Do not skip any model. If a model exists, the admin needs to CRUD it.

THINK — WHAT DOES THIS ADMIN PANEL NEED?
- Content management (pages, blocks, navigation) — ALWAYS
- User management — if the project has user accounts, the admin MUST be able to view, edit,
  and manage users. This is standard in every admin panel.
- If e-commerce: the admin runs a BUSINESS. Think like a shop owner. What do they need?
  They need to see orders, manage products, adjust prices, configure shipping, view customers,
  manage coupons, change tax rates. ALL of this must be in the admin — not in config files.
- DASHBOARD: The admin panel index page must NOT be empty. Build Filament Widgets that give
  the admin an at-a-glance overview of their business. Think: what numbers does a site owner
  check first thing in the morning? Recent activity? Key metrics? Pending actions?
  Use Filament's StatsOverviewWidget and ChartWidget. The widgets must reflect the actual
  functionality of THIS project (don't add e-commerce stats to a portfolio site).

CREATE:
1. A Filament Resource for EVERY model that holds business data. For each one, decide:
   - What columns to show in the list table?
   - What filters and search make sense?
   - What fields does the form need?
   - Is it read-only (like orders) or full CRUD (like products)?

2. PageResource — this one is special because it has the block Builder.
   Read EVERY block blade view in resources/views/themes/default/blocks/.
   Look at the data key comments at the top of each file. The admin Builder MUST create form fields
   that match EXACTLY those data keys. This is the contract between admin and frontend.

   Example: if hero.blade.php uses \$block->data['heading'], \$block->data['subheading'], \$block->data['cta_text']
   then PageResource Builder must have matching form fields for those exact keys.

3. Dashboard Widgets — create widgets appropriate for THIS project's functionality.
   Think about what the admin cares about most. Examples of reasoning:
   - A shop owner cares about: today's orders, revenue, low stock, pending shipments
   - A content site admin cares about: total pages, recently updated, draft pages
   - A booking site admin cares about: upcoming bookings, today's schedule
   YOU decide what makes sense. Do not add widgets for features that don't exist.

4. TRANSLATABLE FIELDS — A helper class already exists at app/Filament/Support/TranslatableFields.php.
   You MUST use it for ALL models that use Spatie HasTranslations. It generates locale tabs automatically.

   Usage in Resource form:
     use App\Filament\Support\TranslatableFields;

     TranslatableFields::tabs(fn (string \$locale) => [
         TranslatableFields::field('title', \$locale, 'Title', required: true),
         TranslatableFields::field('meta_description', \$locale, 'Meta Description', 'textarea'),
     ])

   For block Builder fields, use blockTabs() and blockField() instead (dot notation storage):
     TranslatableFields::blockTabs(fn (string \$locale) => [
         TranslatableFields::blockField('heading', \$locale, 'Heading', required: true),
     ])

   In Create pages, use mutateFormDataBeforeCreate() with TranslatableFields::collectTranslations().
   In Edit pages, use mutateFormDataBeforeSave() with TranslatableFields::saveTranslations().
   Read the helper class for full API documentation.

   WARNING: Do NOT bind a plain TextInput directly to a HasTranslations attribute —
   it will show "[object Object]" instead of text. Always use TranslatableFields.

5. Register CuratorPlugin in AdminPanelProvider (if not already done)

6. Documentation files:
   - CLAUDE.md — Tessera conventions for AI (block system, admin, how to add features)
   - .ai/platform.md — architecture overview (models, services, relationships)
   - .ai/conventions.md — coding standards (strict types, naming, where files go)
   - .ai/blocks.md — EVERY block type with its data keys and admin field mapping

PRINCIPLES:
- The admin and frontend MUST be in sync. If a block view reads a key,
  the admin MUST have a field that writes to that key. No exceptions.
- Every business value that exists in the system must be editable by the admin.
  If the admin cannot change it without a developer, it's a bug.
- If you're unsure whether the admin needs a particular feature — they do.
  A good admin panel is COMPLETE. The admin should never need to touch code or config files.

INTEGRATION CHECK — Your code does NOT exist in isolation. Other AI steps already created
models, middleware, routes, services, and theme views. Your admin resources must match them.
Before finishing, verify:
- Every model you create a Resource for actually EXISTS in app/. Read the model file first.
- Column names in your table() match the ACTUAL database migration column names — read the migration.
- If the theme uses specific block data keys (e.g., \$block->data['heading']), your Builder
  form fields MUST use those EXACT keys. Read the blade views in resources/views/themes/default/blocks/.
- If you reference a relationship in the resource, verify it exists on the model.
Rule: NEVER assume a column, relationship, or key name — READ the source file and match it exactly.
PROMPT,
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

            // Auto-fix Filament 5 namespace issues (AI often uses Filament 4 namespaces)
            $this->fixFilamentNamespaces();
        } // end if !isStepDone('admin')

        // Step D: Content & pages
        if ($this->memory->isStepDone('content')) {
            Console::success('Writing content and seeding data (already done)');
        } else {
            $this->memory->startStep('content');
            $this->steps->runAi(
                name: 'Writing content and seeding data',
                complexity: Complexity::MEDIUM,
                prompt: <<<PROMPT
CONTINUE working on the Tessera project. Models, views, admin, and block views are all set up.

PROJECT: {$desc}
LANGUAGES: {$langs}
E-COMMERCE: {$shop}

THINK: What pages does THIS specific business need?
A restaurant needs: Home, Menu, About, Reservations, Contact.
A web shop needs: Home, Products, About, FAQ, Contact.
A portfolio needs: Home, Projects, About, Contact.
An agency needs: Home, Services, Portfolio, Team, Contact.
Decide based on the project description.

CREATE:
1. SEEDER — DatabaseSeeder that creates:
   - Admin user with email 'admin@tessera.test' and password 'password' (properly hashed)
   - Pages appropriate for THIS business (see above — think about it)
   - Each page with blocks that make sense for its purpose
   - Block data keys MUST match what the blade views expect! Read the block views first.
   - For multilingual projects, block data values should be translation arrays that match
     the locale keys the Block model's resolution method expects.
   - Mark one page as the homepage (is_homepage = true, or use sort_order = 0).
     The PageController renders the homepage at / without redirect — no hardcoded slug.
   - Header navigation linking to all main pages
   - Footer navigation (legal, social, secondary pages)
   - EVERY navigation URL must point to an actual working route or page slug.
     Verify: for each nav URL you create, ask "does this route/page actually exist?"
     CMS pages are at /{slug}. Shop routes are defined in routes/shop.php.
     Do NOT invent translated URLs for routes that use English paths.
     For shop links, use the EXACT route paths from routes/shop.php (e.g., /shop, /shop/cart).
     Do NOT guess — read routes/shop.php first.

2. Content rules:
   - ALL content must be REALISTIC for this business — NO lorem ipsum, NO placeholder text
   - Write like a professional copywriter in {$langs}
   - Headlines must be compelling and specific to this business
   - FAQ questions must be ones a real customer would ask
   - Testimonials must sound genuine (use realistic names for the country/culture)

3. IF E-COMMERCE: seed EVERYTHING the shop needs to be functional out of the box.
   Think: "If I open this shop right now, can I browse products and place an order?"
   If something is missing from the seed data, the shop doesn't work. Seed it all:
   categories, products, shipping zones (appropriate for {$country}), shop settings
   (tax rate, currency for that country), coupons — whatever models exist must have seed data.

4. Run: php artisan migrate --force && php artisan db:seed --force

5. config/ai.php — AI configuration file

6. Create .env.example additions: document any new .env keys the project needs
   (payment keys, API keys, etc.) — add them to .env.example with descriptive comments

7. If the project's primary language is not English, create translations for all vendor packages
   that have user-facing strings (Curator, etc.) in lang/vendor/.
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
                timeout: $this->aiTimeout,
            );
            $this->memory->completeStep('content');
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
