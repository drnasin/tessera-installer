<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;
use Tessera\Installer\Memory;
use Tessera\Installer\StepRunner;
use Tessera\Installer\SystemInfo;

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

    private SystemInfo $system;

    private Memory $memory;

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
            . 'Stack: PHP 8.2+, Laravel (latest), Filament (latest), Livewire, Tailwind, MySQL/SQLite.';
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

    public function scaffold(string $directory, array $requirements, AiTool $ai, SystemInfo $system, Memory $memory): bool
    {
        $this->fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $this->ai = $ai;
        $this->system = $system;
        $this->memory = $memory;
        $this->requirements = $requirements;
        $this->steps = new StepRunner($ai, $this->fullPath);

        $memory->init($directory, 'laravel', $requirements, $system->buildAiContext());

        Console::line();
        Console::bold('Building your project — this takes about 10-15 minutes.');
        Console::line('  Go grab a coffee, AI is doing all the work.');
        Console::line();

        // Step 1: Create Laravel project (runs in parent dir since project doesn't exist yet)
        $parentRunner = new StepRunner($ai, getcwd());
        $result = $parentRunner->runCommand(
            name: '[1/8] Create Laravel project',
            command: "composer create-project laravel/laravel {$directory} --prefer-dist --no-interaction",
            verify: fn (): ?string => is_file($this->fullPath . '/artisan') ? null : 'artisan file not found',
            fixHint: "Run: composer create-project laravel/laravel {$directory} --prefer-dist",
        );

        if (! $result) {
            return false;
        }

        // Step 2: Install packages
        Console::line();
        Console::spinner('[2/8] Installing packages...');
        if (! $this->installAllPackages()) {
            return false;
        }

        // Step 3: Filament setup
        Console::line();
        Console::spinner('[3/8] Setting up admin panel...');
        if (! $this->setupFilament()) {
            return false;
        }

        // Step 4: Publish configs
        Console::line();
        Console::spinner('[4/8] Publishing configs...');
        $this->publishConfigs();

        // Step 5: Create directory structure
        Console::line();
        Console::spinner('[5/8] Creating project structure...');
        if (! $this->createStructure()) {
            return false;
        }

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

    /**
     * Detect installed package versions from composer.lock.
     */
    private function detectVersions(): string
    {
        $lockFile = $this->fullPath . '/composer.lock';

        if (! is_file($lockFile)) {
            return 'Laravel (latest) with Filament (latest)';
        }

        $lock = json_decode((string) file_get_contents($lockFile), true);
        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

        $versions = ['PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION];
        $detect = ['laravel/framework' => 'Laravel', 'filament/filament' => 'Filament', 'livewire/livewire' => 'Livewire'];

        foreach ($packages as $pkg) {
            $name = $pkg['name'] ?? '';
            if (isset($detect[$name])) {
                $ver = ltrim($pkg['version'] ?? '', 'v');
                // Extract major version (e.g. "12.5.3" → "12")
                $major = explode('.', $ver)[0];
                $versions[] = $detect[$name] . ' ' . $major;
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
        $this->memory->startStep('core_models');
        $this->steps->runAi(
            name: 'Creating database models and services',
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

STEP 2 — ALWAYS CREATE (every Tessera project has these):
1. CORE MODELS in app/Core/Models/:
   - Page.php (title, slug, meta_title, meta_description, og_image, is_published, published_at)
   - Block.php (page_id, type, data JSON, order, is_visible)
     CRITICAL: Block.data is a JSON column. Each block type stores its own structure.
     Example: hero block data = {"heading": "...", "subheading": "...", "cta_text": "...", "cta_url": "...", "background_image": "..."}
   - Navigation.php (label, url, location, parent_id, order, is_active)

2. MIGRATIONS for all models (use timestamps, soft deletes where appropriate)

3. SERVICES in app/Core/Services/:
   - PageRenderer.php — resolves page by slug, loads blocks, renders with theme
   - BlockRegistry.php — maps block type string to blade view path
   - ThemeManager.php — returns active theme name

4. HELPERS in app/Core/helpers.php:
   - curator_url(\$media): handles both numeric IDs (Curator) and legacy URL strings
   - module_active(string \$module): checks if a module directory exists

5. PageController in app/Core/Http/ — catch-all for /{slug?}

6. Register helpers autoload in composer.json "files" array

STEP 3 — IF E-COMMERCE ({$shop}):
Create a full shop module in app/Modules/Shop/:
- Models: Product, ProductVariant, Category, Cart, CartItem, Order, OrderItem, Coupon
- Enums: OrderStatus, PaymentStatus, CouponType
- PaymentGateway interface in Payments/ with implementations for: {$payments}
  Each gateway must have: charge(), refund(), webhookHandler(), getConfigKeys()
  getConfigKeys() returns array of .env keys needed (e.g., ['STRIPE_KEY', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET'])
- ShippingCalculator interface + zone-based implementation
- Livewire components: ProductCard, ProductFilter, CartWidget, CartPage, Checkout
- Shop routes in routes/shop.php (products, cart, checkout, webhooks)
- config/shop.php (currency, tax_rate, shipping_zones, payment providers, free_shipping_threshold)
- ShopSeeder with realistic sample data for THIS business type
- ShopServiceProvider registered in bootstrap/providers.php

IMPORTANT:
- declare(strict_types=1), typed properties, return types EVERYWHERE
- Use SQLite as default database
- Every Livewire component must have a corresponding blade view
- Payment gateways must define getConfigKeys() so we can tell the developer what to configure
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
            name: 'Designing frontend theme and pages',
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
   - partials/footer.blade.php — columns: links, contact info, social. Copyright year.

2. BLOCK VIEWS in resources/views/themes/default/blocks/:
   Each block view receives \$block (Block model). Access data via \$block->data['key'].

   For EVERY block view you create, document the expected data keys as a comment at the top:
   {{-- Data keys: heading (string), subheading (string), cta_text (string), cta_url (string), background_image (int|string) --}}

   Create these blocks (adjust to what makes sense for THIS project):
   - hero.blade.php — heading, subheading, CTA button, background (image or gradient)
   - text.blade.php — rich text content block
   - text-image.blade.php — text + image side by side (with alignment option)
   - feature-cards.blade.php — array of cards, each with icon, title, description
   - cta-banner.blade.php — call-to-action with heading, text, button
   - contact-form.blade.php — Livewire contact form with validation
   - faq-accordion.blade.php — collapsible items (Alpine.js)
   - gallery-masonry.blade.php — grid of images from Curator
   - testimonials.blade.php — customer reviews with name, text, rating
   - Add MORE block types if THIS project needs them (e.g., menu-list for restaurant, pricing-table for SaaS, team-members, etc.)

3. Routing: catch-all /{slug?} in bootstrap/app.php (AFTER Filament routes, in 'then:' callback)

4. config/platform.php — site_name, default_theme, supported_locales

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
            name: 'Building admin panel',
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

2. IF E-COMMERCE ({$shop}):
   - ProductResource — name, slug, description, price, images, category, variants, active toggle
   - CategoryResource — name, slug, parent, order
   - OrderResource — read-only list with status filters, order details, customer info
   - CouponResource — code, type (percent/fixed), value, valid dates, usage limits

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
            name: 'Writing content and seeding data',
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
   - Pages appropriate for THIS business (see above — think about it)
   - Each page with blocks that make sense for its purpose
   - CRITICAL: Block data keys MUST match what the blade views expect!
     Read the block views you created to know the exact key names.
     Example: if hero.blade.php reads \$block->data['heading'], the seeder must set ['heading' => '...']
   - Header navigation linking to all main pages
   - Footer navigation (legal, social, secondary pages)

2. Content rules:
   - ALL content must be REALISTIC for this business — NO lorem ipsum, NO placeholder text
   - Write like a professional copywriter in {$langs}
   - Headlines must be compelling and specific to this business
   - FAQ questions must be ones a real customer would ask
   - Testimonials must sound genuine (use realistic names for the country/culture)

3. IF E-COMMERCE: also seed categories, sample products with realistic names/prices/descriptions

4. Run: php artisan migrate --force && php artisan db:seed --force

5. config/ai.php — AI configuration file

6. Create .env.example additions: document any new .env keys the project needs
   (payment keys, API keys, etc.) — add them to .env.example with descriptive comments
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

        // Step G: Generate developer handoff — SETUP.md with what the dev needs to do
        $this->steps->runAi(
            name: 'Generating setup instructions for developer',
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

7. COMMON TASKS
   - How to add a new page (admin panel)
   - How to add a new block type (create blade view + add to BlockRegistry + add to PageResource builder)
   - How to change the theme/colors
   - How to add a new language

Write in CLEAR, simple language. The developer is a junior — don't assume they know
what a webhook is. Explain briefly when needed.
PROMPT,
            verify: fn (): ?string => is_file($this->fullPath . '/SETUP.md') ? null : 'SETUP.md not created',
            skippable: true,
            timeout: 300,
        );

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
