<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\CommandRunner;
use Tessera\Installer\Complexity;
use Tessera\Installer\Console;
use Tessera\Installer\DatabaseIdentifier;
use Tessera\Installer\EnvFile;
use Tessera\Installer\EnvPolicy;
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
            .'Stack: PHP 8.4+, Laravel (latest), Filament (latest), Livewire, Tailwind, MySQL/SQLite.';
    }

    public function preflight(): array
    {
        $missing = [];

        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            $missing[] = 'PHP 8.4+ (found: '.PHP_VERSION.')';
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

        // Step 6: AI builds everything (delegated to the YAML manifest engine).
        // The 6 prompt steps that used to be inlined here as PHP heredocs now
        // live in `stacks/laravel.yaml` as versioned templates with explicit
        // gates, fingerprints, and event tracing — same contract as Static,
        // Go, Node. Pre-AI shell sequence above stays in PHP because Laravel's
        // setup is too tool-specific to push through YAML.
        Console::line();
        Console::bold('[6/8] AI is building your project — this is the big one...');
        Console::line('  AI is creating models, theme, admin, content, and tests.');
        Console::line('  This takes a few minutes. Sit tight.');
        Console::line();

        $aiOk = (new YamlStackRunner)->run(
            directory: $directory,
            stackName: 'laravel',
            requirements: $requirements,
            router: $router,
            system: $system,
            memory: $memory,
        );

        if (! $aiOk) {
            return false;
        }

        // Step 7: Run-and-fix-tests loop. This is post-manifest because it's
        // a hybrid — `php artisan test` is shell, but failure responses feed
        // back into a follow-up AI prompt, retried up to 3 times. That kind
        // of stateful loop doesn't fit the linear plan model in v1.
        if (! $this->memory->isStepDone('tests_fixed')) {
            $this->runAndFixTests();
            $this->memory->completeStep('tests_fixed');
        } else {
            Console::success('AI: Fix failing tests (already done)');
        }

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

        if ($coreExit === 0 && $devExit === 0) {
            // Generate autoload once
            Console::spinner('Generating autoload...');
            Console::exec('composer dump-autoload', $this->fullPath);
            Console::success('Install packages');

            return true;
        }

        // Bulk failed for core and/or dev — fall back to StepRunner for individual installs with retry.
        // Run the failing set(s) only; skip the set that already succeeded.
        $ok = true;
        if ($coreExit !== 0) {
            $ok = $this->steps->installPackages('Install core packages', $packages) && $ok;
        }
        if ($devExit !== 0) {
            $ok = $this->steps->installPackages('Install dev tools', $devPackages, dev: true) && $ok;
        }

        if ($ok) {
            Console::spinner('Generating autoload...');
            Console::exec('composer dump-autoload', $this->fullPath);
        }

        return $ok;
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
            $env = EnvFile::setKey($env, 'DB_CONNECTION', 'sqlite');
            $env = EnvFile::removeKey($env, 'DB_HOST');
            $env = EnvFile::removeKey($env, 'DB_PORT');

            // Keep DB_DATABASE only if it already points at the sqlite file;
            // for any other value, drop it so Laravel uses the default path.
            if (preg_match('/^DB_DATABASE=.*database\.sqlite/m', $env) !== 1) {
                $env = EnvFile::removeKey($env, 'DB_DATABASE');
            }

            $env = EnvFile::removeKey($env, 'DB_USERNAME');
            $env = EnvFile::removeKey($env, 'DB_PASSWORD');
        }

        file_put_contents($envFile, $env);

        return $db;
    }

    /**
     * Ask for credentials and try to connect to MySQL/MariaDB or PostgreSQL.
     * Returns true if connection succeeded, false if it failed.
     * On success, writes credentials to $env (passed by reference).
     *
     * Hardening notes:
     *   - Credentials reach the DB client via array argv (no shell), so user
     *     input can never inject commands.
     *   - Passwords go through env vars (PGPASSWORD / MYSQL_PWD) rather than
     *     -p flags; argv is visible in `ps`, env vars of another user's
     *     process are not.
     *   - Database name and username are validated against a strict allowlist
     *     before being embedded in DDL (CREATE DATABASE cannot be parameterised).
     *   - Writing to `.env` goes through EnvFile::setKey which quotes/escapes
     *     any dangerous characters; raw preg_replace on user input corrupted
     *     the file on passwords containing `#`, `$`, quote, or newline.
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

        $dbUser = $this->askValidatedIdentifier('Database username', $defaultUser);
        if ($dbUser === null) {
            return false;
        }

        $dbPass = Console::ask('Database password (leave empty for none)', '');

        $dbName = $this->askValidatedIdentifier('Database name', $projectName);
        if ($dbName === null) {
            return false;
        }

        // Validate host/port shape too — they reach argv but we still don't
        // want random bytes flowing into DB clients.
        if (! $this->isPlausibleHost($dbHost) || ! ctype_digit($dbPort) || (int) $dbPort < 1 || (int) $dbPort > 65535) {
            Console::warn('Host must be a hostname or IP, port must be a number 1-65535.');

            if (Console::confirm('Retry with different credentials?', true)) {
                return $this->configureDatabaseServer($db, $env, $projectName);
            }

            return false;
        }

        $cli = $db === 'mariadb' ? 'mariadb' : ($isPostgres ? 'psql' : 'mysql');
        $runner = new CommandRunner();

        // 1) Test connection.
        $testResult = $isPostgres
            ? $runner->run(
                argv: ['psql', '-h', $dbHost, '-p', $dbPort, '-U', $dbUser, '-c', 'SELECT 1;'],
                cwd: $this->fullPath,
                env: $this->dbEnv($isPostgres ? 'postgres' : 'mysql', $dbPass),
                timeout: 15,
            )
            : $runner->run(
                argv: [$cli, '-u', $dbUser, '-h', $dbHost, '-P', $dbPort, '-e', 'SELECT 1;'],
                cwd: $this->fullPath,
                env: $this->dbEnv('mysql', $dbPass),
                timeout: 15,
            );

        if (! $testResult->succeeded()) {
            Console::line();
            Console::warn("Could not connect to {$label}: ".trim($testResult->combinedOutput()));

            if (Console::confirm('Retry with different credentials?', true)) {
                return $this->configureDatabaseServer($db, $env, $projectName);
            }

            return false;
        }

        // 2) Connection works — write credentials to .env via safe quoting.
        $env = EnvFile::setKey($env, 'DB_CONNECTION', $connection);
        $env = EnvFile::setKey($env, 'DB_HOST', $dbHost);
        $env = EnvFile::setKey($env, 'DB_PORT', $dbPort);
        $env = EnvFile::setKey($env, 'DB_DATABASE', $dbName);
        $env = EnvFile::setKey($env, 'DB_USERNAME', $dbUser);
        $env = EnvFile::setKey($env, 'DB_PASSWORD', $dbPass);

        // 3) Try to create the database. MySQL/MariaDB need quoted
        //    identifiers for allowed names like `my-restaurant`.
        $mySqlDatabaseIdentifier = $isPostgres ? null : DatabaseIdentifier::quoteMySql($dbName);
        $createResult = $isPostgres
            ? $runner->run(
                argv: ['createdb', '-h', $dbHost, '-p', $dbPort, '-U', $dbUser, $dbName],
                cwd: $this->fullPath,
                env: $this->dbEnv('postgres', $dbPass),
                timeout: 15,
            )
            : $runner->run(
                argv: [$cli, '-u', $dbUser, '-h', $dbHost, '-P', $dbPort, '-e', "CREATE DATABASE IF NOT EXISTS {$mySqlDatabaseIdentifier};"],
                cwd: $this->fullPath,
                env: $this->dbEnv('mysql', $dbPass),
                timeout: 15,
            );

        if ($createResult->succeeded()) {
            Console::success("Database '{$dbName}' ready");

            return true;
        }

        // 4) Auto-create failed — maybe it already exists. Probe it.
        $checkResult = $isPostgres
            ? $runner->run(
                argv: ['psql', '-h', $dbHost, '-p', $dbPort, '-U', $dbUser, '-d', $dbName, '-c', 'SELECT 1;'],
                cwd: $this->fullPath,
                env: $this->dbEnv('postgres', $dbPass),
                timeout: 15,
            )
            : $runner->run(
                argv: [$cli, '-u', $dbUser, '-h', $dbHost, '-P', $dbPort, $dbName, '-e', 'SELECT 1;'],
                cwd: $this->fullPath,
                env: $this->dbEnv('mysql', $dbPass),
                timeout: 15,
            );

        if ($checkResult->succeeded()) {
            Console::success("Database '{$dbName}' already exists");

            return true;
        }

        // 5) Database doesn't exist and we can't create it — wait for user.
        Console::warn('Could not create database: '.trim($createResult->combinedOutput()));
        Console::line("Please create the '{$dbName}' database manually, then press Enter to continue.");
        Console::ask('Press Enter when ready');

        $verifyResult = $isPostgres
            ? $runner->run(
                argv: ['psql', '-h', $dbHost, '-p', $dbPort, '-U', $dbUser, '-d', $dbName, '-c', 'SELECT 1;'],
                cwd: $this->fullPath,
                env: $this->dbEnv('postgres', $dbPass),
                timeout: 15,
            )
            : $runner->run(
                argv: [$cli, '-u', $dbUser, '-h', $dbHost, '-P', $dbPort, $dbName, '-e', 'SELECT 1;'],
                cwd: $this->fullPath,
                env: $this->dbEnv('mysql', $dbPass),
                timeout: 15,
            );

        if ($verifyResult->succeeded()) {
            Console::success("Database '{$dbName}' ready");

            return true;
        }

        Console::warn("Still cannot access database '{$dbName}': ".trim($verifyResult->combinedOutput()));

        return false;
    }

    /**
     * Ask for a value and validate it against DatabaseIdentifier rules.
     * Returns null if the user declines to retry with a valid value.
     */
    private function askValidatedIdentifier(string $prompt, string $default): ?string
    {
        while (true) {
            $value = Console::ask($prompt, $default);

            if (DatabaseIdentifier::isValid($value)) {
                return $value;
            }

            Console::warn(
                "Invalid {$prompt}: must start with a letter or underscore and contain only "
                .'letters, digits, underscore, or hyphen (max 63 chars).',
            );

            if (! Console::confirm('Try again?', true)) {
                return null;
            }
        }
    }

    /**
     * Cheap shape check for DB host. Hostnames and IP literals; rejects shell
     * metacharacters and whitespace. Real hostname validation is left to the
     * DB client — this only guards against obvious garbage.
     */
    private function isPlausibleHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        return (bool) preg_match('/\A[A-Za-z0-9._:\-]+\z/', $host);
    }

    /**
     * Build an EnvPolicy for DB-client subprocesses, injecting the password
     * via the engine's designated env var (PGPASSWORD for PostgreSQL,
     * MYSQL_PWD for MySQL/MariaDB). This keeps the password out of argv,
     * where `ps` / Windows Task Manager would expose it.
     */
    private function dbEnv(string $engine, string $password): EnvPolicy
    {
        $policy = EnvPolicy::buildTool();

        if ($password === '') {
            return $policy;
        }

        $extraKey = $engine === 'postgres' ? 'PGPASSWORD' : 'MYSQL_PWD';

        return $policy->withExtra([$extraKey => $password]);
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
