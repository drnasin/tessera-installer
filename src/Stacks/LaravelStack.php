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
        $this->configureDatabase();
        Console::success('Database configured: '.($this->requirements['database'] ?? 'sqlite'));

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

5. PageController in app/Core/Http/ — catch-all for /{slug?}
   The default slug must match the homepage slug used in the seeder.

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
Think like an E-COMMERCE EXPERT. Follow industry standards for online shops.
A shop is not just products + cart — it's a complete system with user accounts, order management,
shipping configuration, tax handling, and payment processing. Build ALL of it.

Create a full shop module in app/Modules/Shop/:

MODELS:
- Product, ProductVariant, Category, Cart, CartItem, Order, OrderItem, Coupon
- ShippingZone — ALL shipping configuration must be in the DATABASE, not config files:
  * name (string, e.g. "Zagreb", "Hrvatska", "EU")
  * countries (JSON array of country codes, e.g. ["HR"])
  * postal_code_from / postal_code_to (nullable int — for sub-country zones like Zagreb 10000-10999)
  * base_cost (int, cents), per_kg_cost (int, cents)
  * free_shipping_threshold (nullable int, cents — null means never free)
  * is_active (bool), sort_order (int)
  The ShippingCalculator reads zones from DB, matches by postal code ranges first, then by country.
  Admin can add/edit/delete zones, change prices, set free shipping thresholds — NO code changes needed.
- ShopSetting — key/value store for shop-wide settings (tax_rate, currency, etc.)
  Administrable via Filament. Cached. Helper: shop_setting('tax_rate', 25)

ENUMS: OrderStatus, PaymentStatus, CouponType

PAYMENT GATEWAYS:
- PaymentGateway interface in Payments/ with implementations for: {$payments}
  Each gateway must have: charge(), refund(), webhookHandler(), getConfigKeys()
  getConfigKeys() returns array of .env keys needed (API keys come from .env, not DB)
  PAYMENT GATEWAY PATTERN: There are two common payment flows — understand which to use:
    a) REDIRECT gateways (CorvusPay, PayPal, etc.): charge() builds form data + signature,
       returns a redirect URL. The customer is sent to the provider's hosted page.
       Needs: success/cancel callback routes + webhook route for async notifications.
    b) API gateways (Stripe, Mollie, etc.): charge() calls an API, creates a PaymentIntent/session,
       returns a client secret or redirect URL. Needs: webhook route for payment confirmation.
  charge() must return a PaymentResult DTO with: success, redirectUrl, transactionId, error.

SHIPPING:
- ShippingCalculator interface + DB-driven implementation (reads ShippingZone model)
  Must include zoneFromPostalCode(string \$postalCode): string method that matches
  postal code against ShippingZone ranges. Falls back to country matching if no postal range matches.
  NEVER hardcode postal code ranges or prices in PHP code or config files.

USER ACCOUNTS (CRITICAL — e-commerce REQUIRES user functionality):
- Registration page (name, email, password, password confirmation)
- Login page (email, password, remember me)
- Password reset flow (forgot password → email → reset form)
- User profile page (edit name, email, change password)
- Order history page (list of past orders with status, date, total)
- Order detail page (items, shipping info, payment status, tracking)
- Address book (saved shipping/billing addresses, set default, reuse at checkout)
- Guest checkout must ALSO work — checkout allows both logged-in users and guests.
  For guests: collect email, offer "create account" checkbox at checkout.
  For logged-in users: pre-fill from profile, save address to address book.
Use Laravel's built-in Auth scaffolding where possible. Create Livewire components for:
  - Auth views in resources/views/auth/ (login, register, forgot-password, reset-password)
  - Profile page, order history, order detail, address book
These are standard e-commerce features — every online shop has them.

LIVEWIRE COMPONENTS:
- ProductCard, ProductFilter, CartWidget, CartPage, Checkout, AddToCart
  - AddToCart: variant selector, quantity +/- buttons, add-to-cart button (for product detail page)
  - CartPage: full cart view with quantity editing, coupon, postal code for shipping estimate
  - Checkout: shipping/billing forms, payment method selection, order summary
    Pre-fill from user profile/address book if logged in.
  - ProductFilter: category filter, price range, sorting (for product listing page)
- PRODUCT DETAIL PAGE: Create a product detail view (show single product with images,
  description, variants, price, and the AddToCart Livewire component). This is NOT a Livewire
  full-page component — it's a Blade view with an embedded AddToCart component.
  Route: /shop/product/{product:slug}
- Shop routes in routes/shop.php (products, cart, checkout, webhooks, product detail, auth, profile, orders)
- config/shop.php — ONLY technical settings: payment provider class mappings, .env key references.
  NO prices, NO zone definitions, NO tax rates — those go in ShopSetting / ShippingZone DB tables.
- ShopSeeder with realistic sample data for THIS business type:
  * Default shipping zones with sensible prices for the target country
  * Default shop settings (tax_rate, currency, etc.)
  * Sample products, categories, coupons
- ShopServiceProvider registered in bootstrap/providers.php
  CRITICAL: Livewire components in non-standard namespaces (like App\Modules\Shop\Livewire)
  must be MANUALLY REGISTERED in the ServiceProvider boot() method:
    Livewire::component('shop.cart-widget', CartWidget::class);
    Livewire::component('shop.cart-page', CartPage::class);
    Livewire::component('shop.checkout', Checkout::class);
    Livewire::component('shop.add-to-cart', AddToCart::class);
    Livewire::component('shop.product-filter', ProductFilter::class);
  Without this, Livewire cannot find the components and throws "Unable to find component" errors.

IMPORTANT:
- declare(strict_types=1), typed properties, return types EVERYWHERE
- The database is already configured in .env — do NOT change it. Use whatever DB_CONNECTION is set.
- Every Livewire component must have a corresponding blade view
- Payment gateways must define getConfigKeys() so we can tell the developer what to configure
- Database migrations must be compatible with BOTH SQLite and MySQL/PostgreSQL:
  Do NOT use MySQL-specific syntax (e.g., ->unsigned() on non-integer columns, ENUM columns).
  Use Laravel's column types which abstract DB differences.
- NEVER hardcode business values (prices, tax rates, zone names, thresholds, labels) in:
  * PHP classes (no hardcoded arrays of zones/prices)
  * Config files (config/*.php is for technical settings only)
  * Blade views (no hardcoded currency symbols or rates)
  ALL business values → database tables → admin panel → seeder for defaults.
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

   AUTH & USER PAGES — Create styled views for all user account pages:
   - resources/views/auth/ — login, register, forgot-password, reset-password
   - resources/views/shop/profile.blade.php — user profile (edit name, email, password)
   - resources/views/shop/orders.blade.php — order history list
   - resources/views/shop/order-detail.blade.php — single order view
   - resources/views/shop/addresses.blade.php — saved addresses management
   All auth/account pages must use the shop layout and match the site's visual style.
   Header should show login/register links for guests, and profile/orders/logout for logged-in users.

4. Routing: catch-all /{slug?} in bootstrap/app.php (AFTER Filament routes, in 'then:' callback)
   NEVER use Route::get('/{slug?}', function() {}) — use controller syntax.
   Route closures cannot be cached by `php artisan route:cache`.

5. config/platform.php — site_name, default_theme, supported_locales, address, contact_phone, contact_email, social links

6. VISUAL CONSISTENCY: Verify every UI element is readable on its background (e.g., white inputs on
   white backgrounds are invisible — add contrast). Test mentally by imagining the page rendered.

7. NULL-SAFE CONTENT: All blade views must handle null/empty data gracefully. Use null coalescing
   (\$block->data['key'] ?? '') for all data access. RichEditor/TipTap content MUST never be null —
   use empty string defaults. Blade {!! !!} output on null content can crash renderers.

8. MULTILINGUAL: All hardcoded text in templates must be locale-aware for all configured languages.
   Navigation labels and URLs come from the DB (HasTranslations) so they switch automatically.

DESIGN — make it look like a REAL website, not a template:
- Style: {$designStyle}
- Colors: {$designColors}
- Mobile-first responsive design
- Hero: visually striking, gradient or background image, large typography
- Cards: hover effects (shadow-lg, scale-105 transition)
- Typography hierarchy: text-5xl/6xl for hero, text-3xl for sections, proper line-height
- Spacing: generous padding (py-16, py-24 for sections)
- Navigation: sticky, transparent-to-solid on scroll (Alpine.js), hamburger on mobile
- Footer: dark background, 3-4 columns, social icons
- Images: use curator_url(\$block->data['image']) helper for all media
- ALL content language: {$langs}
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

IMPORTANT — VERIFY ALL IMPORTS:
Before using any Filament class, verify the namespace is correct for the INSTALLED Filament version.
Filament's namespace structure changes between major versions. Check the actual source files in
vendor/filament/ if you're unsure whether a class is in Filament\Actions, Filament\Tables\Actions, etc.
A wrong namespace causes a fatal "Class not found" error that breaks the entire admin.

Common Filament v5 namespaces (verify against vendor/ if unsure):
  - Filament\Resources\Resource (base resource class)
  - Filament\Forms\Components\TextInput, RichEditor, Select, Toggle, Builder, Repeater
  - Filament\Tables\Columns\TextColumn, IconColumn, BadgeColumn
  - Filament\Tables\Actions\EditAction, DeleteAction (NOT Filament\Actions for table actions)
  - Filament\Schemas\Components\Tabs (NOT Filament\Forms\Components\Tabs in v5)
  - Awcodes\Curator\Components\Forms\CuratorPicker (NOT Awcodes\Curator\Forms\CuratorPicker)

CREATE:
1. FILAMENT RESOURCES:
   - PageResource with tabs:
     * Content tab: Builder field with ALL block types (read the blade views!)
       - Each Builder\Block must have form fields matching the blade view's data keys
       - Use appropriate field types: TextInput, Textarea, RichEditor, Toggle, Select, CuratorPicker
       - For array data (like feature cards items), use Repeater inside the block
     * SEO tab: meta_title, meta_description, og_image (CuratorPicker)
     * Settings tab: is_published, published_at, slug (auto-generated from title)
   - NavigationResource — CRUD with location (header/footer), parent_id, order, active toggle

     TRANSLATABLE FIELDS — A helper class already exists at app/Filament/Support/TranslatableFields.php.
     You MUST use it for ALL models that use Spatie HasTranslations. It generates locale tabs automatically.

     Usage in Resource form:
       use App\Filament\Support\TranslatableFields;

       TranslatableFields::tabs(fn (string $locale) => [
           TranslatableFields::field('title', $locale, 'Title', required: true),
           TranslatableFields::field('meta_description', $locale, 'Meta Description', 'textarea'),
       ])

     For block Builder fields, use blockTabs() and blockField() instead (dot notation storage):
       TranslatableFields::blockTabs(fn (string $locale) => [
           TranslatableFields::blockField('heading', $locale, 'Heading', required: true),
       ])

     In Create pages, use mutateFormDataBeforeCreate() with TranslatableFields::collectTranslations().
     In Edit pages, use mutateFormDataBeforeSave() with TranslatableFields::saveTranslations().
     Read the helper class for full API documentation.

     WARNING: Do NOT bind a plain TextInput directly to a HasTranslations attribute —
     it will show "[object Object]" instead of text. Always use TranslatableFields.

2. IF E-COMMERCE ({$shop}):
   - ProductResource — name, slug, description, price, images, category, variants, active toggle
   - CategoryResource — name, slug, parent, order
   - OrderResource — read-only list with status filters, order details, customer info
   - CouponResource — code, type (percent/fixed), value, valid dates, usage limits
   - ShippingZoneResource — CRUD for shipping zones: name, countries, postal code ranges,
     base_cost, per_kg_cost, free_shipping_threshold, is_active, sort_order.
     Admin must be able to fully manage shipping zones and prices without touching code.
   - ShopSettingResource (or a Filament custom page) — manage shop-wide settings:
     tax_rate, currency, shop_name, etc. Key-value pairs, all editable by admin.

3. Register CuratorPlugin in AdminPanelProvider (if not already done)

4. Documentation files:
   - CLAUDE.md — Tessera conventions for AI (block system, admin, how to add features)
   - .ai/platform.md — architecture overview (models, services, relationships)
   - .ai/conventions.md — coding standards (strict types, naming, where files go)
   - .ai/blocks.md — EVERY block type with its data keys and admin field mapping

IMPORTANT: The admin and frontend MUST be in sync. If a block view reads a key,
the admin MUST have a field that writes to that key. No exceptions.
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
   - The homepage slug must match the default slug in PageController — these MUST be consistent.
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

3. IF E-COMMERCE: also seed:
   - Categories with realistic names for THIS business type
   - Sample products with realistic names, prices, descriptions, variants
   - Shipping zones appropriate for the target country ({$country}):
     e.g., for Croatia: "Zagreb" (10000-10999), "Hrvatska" (rest of HR), "EU", "Svijet"
     with realistic base costs, per-kg costs, and free shipping thresholds
   - Default shop settings (tax_rate for the country, currency, shop name)
   - Sample coupons (percent and fixed amount)

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

7. SHIPPING & SETTINGS MANAGEMENT (if e-commerce)
   - How to add/edit shipping zones in the admin panel
   - How to change tax rate, currency, free shipping threshold
   - Explain that ALL these values are managed in admin — no code changes needed

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
     * Configure the database in .env based on requirements.
     */
    private function configureDatabase(): void
    {
        $db = $this->requirements['database'] ?? 'sqlite';
        $envFile = $this->fullPath.'/.env';

        if (! is_file($envFile)) {
            return;
        }

        $env = (string) file_get_contents($envFile);
        $projectName = basename($this->fullPath);

        if ($db === 'sqlite') {
            // Laravel default — ensure DB_CONNECTION=sqlite
            $env = preg_replace('/^DB_CONNECTION=.*/m', 'DB_CONNECTION=sqlite', $env);
            // Remove MySQL-specific lines if present
            $env = preg_replace('/^DB_HOST=.*\n?/m', '', $env);
            $env = preg_replace('/^DB_PORT=.*\n?/m', '', $env);
            $env = preg_replace('/^DB_DATABASE=(?!.*database\.sqlite).*\n?/m', '', $env);
            $env = preg_replace('/^DB_USERNAME=.*\n?/m', '', $env);
            $env = preg_replace('/^DB_PASSWORD=.*\n?/m', '', $env);
        } elseif (in_array($db, ['mysql', 'mariadb'], true)) {
            $env = preg_replace('/^DB_CONNECTION=.*/m', 'DB_CONNECTION=mysql', $env);
            $env = preg_replace('/^DB_HOST=.*/m', 'DB_HOST=127.0.0.1', $env);
            $env = preg_replace('/^DB_PORT=.*/m', 'DB_PORT=3306', $env);
            $env = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$projectName}", $env);
            $env = preg_replace('/^DB_USERNAME=.*/m', 'DB_USERNAME=root', $env);
            $env = preg_replace('/^DB_PASSWORD=.*/m', 'DB_PASSWORD=', $env);

            // If these lines don't exist, add them after DB_CONNECTION
            if (! preg_match('/^DB_HOST=/m', $env)) {
                $env = preg_replace('/^(DB_CONNECTION=.*)$/m', "$1\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE={$projectName}\nDB_USERNAME=root\nDB_PASSWORD=", $env);
            }

            // Try to create the database
            $createCmd = $db === 'mariadb'
                ? "mariadb -u root -e \"CREATE DATABASE IF NOT EXISTS \\`{$projectName}\\`;\""
                : "mysql -u root -e \"CREATE DATABASE IF NOT EXISTS \\`{$projectName}\\`;\"";

            Console::execSilent($createCmd, $this->fullPath);
        } elseif ($db === 'postgresql') {
            $env = preg_replace('/^DB_CONNECTION=.*/m', 'DB_CONNECTION=pgsql', $env);
            $env = preg_replace('/^DB_HOST=.*/m', 'DB_HOST=127.0.0.1', $env);
            $env = preg_replace('/^DB_PORT=.*/m', 'DB_PORT=5432', $env);
            $env = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$projectName}", $env);
            $env = preg_replace('/^DB_USERNAME=.*/m', 'DB_USERNAME=postgres', $env);
            $env = preg_replace('/^DB_PASSWORD=.*/m', 'DB_PASSWORD=', $env);

            if (! preg_match('/^DB_HOST=/m', $env)) {
                $env = preg_replace('/^(DB_CONNECTION=.*)$/m', "$1\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE={$projectName}\nDB_USERNAME=postgres\nDB_PASSWORD=", $env);
            }

            // Try to create the database
            Console::execSilent("createdb {$projectName} 2>/dev/null", $this->fullPath);
        }

        file_put_contents($envFile, $env);
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
