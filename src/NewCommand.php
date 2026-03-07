<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * `tessera new {directory}` — AI-powered project scaffolding.
 *
 * Flow:
 * 1. Detect AI tool
 * 2. Interactive conversation with junior dev
 * 3. AI decides architecture (packages, modules, config)
 * 4. Execute: create-project, install packages, configure, scaffold content
 */
final class NewCommand
{
    private string $directory;

    private string $fullPath;

    private AiTool $ai;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
        $this->fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
    }

    public function run(): int
    {
        $this->showBanner();

        // Step 1: Preflight checks
        if (! $this->preflight()) {
            return 1;
        }

        // Step 2: Gather project requirements from junior
        $requirements = $this->gatherRequirements();

        if ($requirements === null) {
            return 1;
        }

        // Step 3: Let AI plan the project
        $plan = $this->planWithAi($requirements);

        if ($plan === null) {
            return 1;
        }

        // Step 4: Confirm plan with junior
        Console::line();
        Console::bold('AI Plan:');
        Console::line($plan);
        Console::line();

        if (! Console::confirm('Izgleda li ti plan OK?')) {
            Console::warn('Prekinuto. Pokreni ponovo kad budes spreman.');

            return 0;
        }

        // Step 5: Execute — create Laravel project
        if (! $this->createProject()) {
            return 1;
        }

        // Step 6: Let AI configure and scaffold everything
        if (! $this->scaffoldWithAi($requirements, $plan)) {
            return 1;
        }

        // Step 7: Final setup
        $this->finalSetup();

        $this->showComplete();

        return 0;
    }

    private function showBanner(): void
    {
        Console::line();
        Console::cyan('╔══════════════════════════════════════╗');
        Console::cyan('║        TESSERA — AI CMS Engine       ║');
        Console::cyan('║    Opisi sto trebas, AI ce graditi   ║');
        Console::cyan('╚══════════════════════════════════════╝');
        Console::line();
    }

    private function preflight(): bool
    {
        // Check PHP
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            Console::error('Trebas PHP 8.2+. Imas: ' . PHP_VERSION);

            return false;
        }
        Console::success('PHP ' . PHP_VERSION);

        // Check Composer
        $composer = Console::execSilent('composer --version');
        if ($composer['exit'] !== 0) {
            Console::error('Composer nije instaliran. Instaliraj ga: https://getcomposer.org');

            return false;
        }
        Console::success('Composer pronaden');

        // Check Node/NPM
        $node = Console::execSilent('node --version');
        if ($node['exit'] !== 0) {
            Console::warn('Node.js nije pronaden — trebat ce za Vite/Tailwind. Nastavljam...');
        } else {
            Console::success('Node.js ' . trim($node['output']));
        }

        // Check AI tool
        $this->ai = AiTool::detect();

        if ($this->ai === null) {
            Console::error('Nijedan AI alat nije pronaden!');
            Console::line('Instaliraj barem jedan:');
            Console::line('  - claude: https://docs.anthropic.com/en/docs/claude-code');
            Console::line('  - gemini: https://ai.google.dev/gemini-api/docs/cli');
            Console::line('  - codex:  https://github.com/openai/codex');

            return false;
        }
        Console::success("AI: {$this->ai->name()}");

        // Check directory
        if (is_dir($this->fullPath)) {
            Console::error("Direktorij '{$this->directory}' vec postoji.");

            return false;
        }
        Console::success("Direktorij: {$this->directory}");

        Console::line();

        return true;
    }

    /**
     * Interactive conversation to understand what the junior needs.
     *
     * @return array<string, mixed>|null
     */
    private function gatherRequirements(): ?array
    {
        Console::bold('Hajdemo napraviti novi projekt!');
        Console::line('Odgovori na par pitanja da AI zna sto graditi.');
        Console::line();

        // Core question — what is this project?
        $description = Console::ask('Opisi projekt (npr. "web stranica za restoran u Zadru")');

        if (trim($description) === '') {
            Console::error('Opis ne moze biti prazan.');

            return null;
        }

        // Does the client have an HTML template?
        $hasTemplate = Console::confirm('Imas li HTML template za dizajn?', false);
        $templatePath = null;

        if ($hasTemplate) {
            $templatePath = Console::ask('Putanja do HTML direktorija');
        }

        // Languages
        $multilingual = Console::confirm('Treba li vise jezika?', false);
        $languages = ['hr'];

        if ($multilingual) {
            $langInput = Console::ask('Koji jezici? (odvojeni zarezom, npr. hr,en,de)', 'hr,en');
            $languages = array_map('trim', explode(',', $langInput));
        }

        // E-commerce
        $needsShop = Console::confirm('Treba li web shop (e-commerce)?', false);
        $shopDetails = null;

        if ($needsShop) {
            $shopDetails = Console::ask('Kakvi proizvodi? (npr. "odjeca s varijantama velicina/boja")');
        }

        // Any special features?
        $special = Console::ask('Posebni zahtjevi? (ili Enter za preskociti)', '');

        Console::line();

        return [
            'description' => $description,
            'has_template' => $hasTemplate,
            'template_path' => $templatePath,
            'languages' => $languages,
            'needs_shop' => $needsShop,
            'shop_details' => $shopDetails,
            'special' => $special,
        ];
    }

    /**
     * Ask AI to plan the project architecture.
     */
    private function planWithAi(array $requirements): ?string
    {
        Console::spinner('AI planira projekt...');

        $prompt = $this->buildPlanPrompt($requirements);

        $response = $this->ai->execute($prompt, getcwd(), 120);

        if (! $response->success) {
            Console::error('AI nije uspio planirati: ' . $response->error);

            return null;
        }

        return $response->output;
    }

    private function buildPlanPrompt(array $requirements): string
    {
        $desc = $requirements['description'];
        $langs = implode(', ', $requirements['languages']);
        $shop = $requirements['needs_shop'] ? "DA — {$requirements['shop_details']}" : 'NE';
        $template = $requirements['has_template'] ? "DA — {$requirements['template_path']}" : 'NE';
        $special = $requirements['special'] ?: 'Nema';

        return <<<PROMPT
Ti si Tessera AI arhitekt. Na temelju opisa projekta, napravi PLAN.

PROJEKT: {$desc}
JEZICI: {$langs}
E-COMMERCE: {$shop}
HTML TEMPLATE: {$template}
POSEBNO: {$special}

Tessera je AI-native CMS na Laravel 12 + Filament 5.3 + Livewire 4 + Tailwind 4.

DOSTUPNI MODULI (aktiviraj samo sto treba):
- shop: E-commerce (proizvodi, varijante, kosarcia, checkout, placanje)
- blog: Blog/novosti
- menu: Jelovnik/katalog s cijenama (restorani, kafici)
- booking: Rezervacijski sustav (termini, kapacitet)
- gallery: Galerije s albumima
- newsletter: Newsletter subscribe + Mailchimp/Brevo sync
- contact: Kontakt forme s honeypot zastitom

COMPOSER PAKETI (uz standard Laravel):
- filament/filament: Admin panel
- awcodes/filament-curator: Media library
- spatie/laravel-permission: Role i dozvole
- spatie/laravel-translatable: Visejezicnost
- spatie/laravel-sluggable: Auto slugovi
- spatie/laravel-tags: Tagovi
- spatie/laravel-medialibrary: Media uploads
- spatie/laravel-sitemap: SEO sitemap
- spatie/laravel-honeypot: Spam zastita
- laravel/scout + meilisearch/meilisearch-php: Search (ako treba shop)
- barryvdh/laravel-dompdf: PDF generiranje (racuni za shop)
- maatwebsite/excel: Excel export (narudzbe, kontakti)
- intervention/image: Image processing

ODGOVORI U OVOM FORMATU (TOCNO ovako, bez markdown code blokova):

NAZIV: [ime projekta]
TIP: [restoran/shop/portfolio/usluge/blog/landing/custom]
TEMA: [ime teme]
BAZA: [mysql/sqlite]
JEZICI: [lista]
MODULI: [lista aktivnih modula]
PAKETI: [samo DODATNI paketi izvan standarda, ili "standard" ako nista extra]
STRANICE:
- / (Pocetna): [blokovi]
- /o-nama (O nama): [blokovi]
- ... (ostale stranice s blokovima)
NAPOMENA: [sto junior treba znati, max 2 recenice]
PROMPT;
    }

    /**
     * Create Laravel project and install Tessera core.
     */
    private function createProject(): bool
    {
        Console::line();
        Console::spinner('Kreiram Laravel projekt...');

        $exit = Console::exec(
            "composer create-project laravel/laravel {$this->directory} --prefer-dist --no-interaction",
        );

        if ($exit !== 0) {
            Console::error('composer create-project nije uspio.');

            return false;
        }

        Console::success('Laravel projekt kreiran');

        // Install core Tessera packages
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

        $packageList = implode(' ', $packages);

        $exit = Console::exec(
            "composer require {$packageList} --no-interaction",
            $this->fullPath,
        );

        if ($exit !== 0) {
            Console::warn('Neki paketi se mozda nisu instalirali. Nastavljam...');
        }

        Console::success('Core paketi instalirani');

        // Dev packages
        Console::spinner('Instaliram dev alate...');

        $devPackages = [
            'laravel/boost',
            'laravel/pint',
            'laravel/telescope',
            'larastan/larastan',
        ];

        Console::exec(
            'composer require --dev ' . implode(' ', $devPackages) . ' --no-interaction',
            $this->fullPath,
        );

        Console::success('Dev alati instalirani');

        return true;
    }

    /**
     * Let AI configure and scaffold the project.
     */
    private function scaffoldWithAi(array $requirements, string $plan): bool
    {
        Console::line();
        Console::spinner('AI konfigurira i gradi projekt...');

        $prompt = $this->buildScaffoldPrompt($requirements, $plan);

        $response = $this->ai->execute($prompt, $this->fullPath, 600);

        if (! $response->success) {
            Console::error('AI scaffold nije uspio: ' . $response->error);
            Console::line('Mozda trebas pokrenuti rucno. Projekt je kreiran u: ' . $this->fullPath);

            return false;
        }

        Console::line();
        Console::line($response->output);
        Console::line();
        Console::success('AI scaffold zavrsen');

        return true;
    }

    private function buildScaffoldPrompt(array $requirements, string $plan): string
    {
        $desc = $requirements['description'];
        $langs = implode(', ', $requirements['languages']);

        return <<<PROMPT
Ti si Tessera AI senior developer. Projekt je UPRAVO kreiran s Laravel 12.
Radis u project root direktoriju. Tvoj posao je KOMPLETNO postaviti projekt.

OPIS PROJEKTA: {$desc}
JEZICI: {$langs}

AI PLAN (koji je vec odobren):
{$plan}

NAPRAVI SVE OVO REDOM:

1. FILAMENT SETUP
   - php artisan filament:install --panels
   - Kreiraj admin usera: admin@tessera.test / password
   - Registriraj CuratorPlugin u AdminPanelProvider

2. DIREKTORIJ STRUKTURA
   Kreiraj Tessera strukturu:
   - app/Core/Models/ (Page, Block, Navigation, Setting)
   - app/Core/Services/ (PageRenderer, BlockRegistry, ThemeManager)
   - app/Core/Http/PageController.php
   - app/Modules/ (za svaki aktivni modul)
   - resources/views/themes/default/ (layouts, blocks, partials)
   - .ai/ direktorij s dokumentacijom

3. MIGRACIJE
   Kreiraj i pokreni migracije za:
   - pages (title, slug, meta_title, meta_description, meta_image, is_published)
   - blocks (page_id, type, data JSON, order, visible)
   - navigations (label, url, page_id, parent_id, position, location)
   - settings (key, value JSON, group)
   - Modul-specificne tablice prema planu

4. MODELI
   Kreiraj Eloquent modele s:
   - fillable, casts, relacije
   - Scopes (published, ordered, itd.)
   - Translatable trait ako je visejezicno

5. TEMA
   Kreiraj default temu:
   - layouts/master.blade.php (Tailwind 4, responsive, SEO meta)
   - partials/header.blade.php (navigacija)
   - partials/footer.blade.php
   - blocks/ — po jedan blade za svaki tip bloka koristen u planu
   - theme.css s CSS varijablama za boje

6. BLOCK REGISTRY
   Registriraj sve blok tipove:
   - hero, text, text-image, feature-cards, gallery-grid, contact-form,
     contact-info, faq-accordion, cta-banner, testimonials, itd.

7. PAGE CONTROLLER + ROUTING
   - PageController koji resolve-a stranicu po slug-u i renderira blokove
   - Route: catch-all u routes/web.php (ali NAKON Filament ruta)
   - Registriraj catch-all u bootstrap/app.php then: callback

8. STRANICE I SADRZAJ
   Kreiraj stranice prema planu sa REALNIM placeholder sadrzajem.
   Svaka stranica treba:
   - SEO meta (title, description)
   - Blokove u smislenom redoslijedu
   - Realan tekst (ne Lorem ipsum)

9. NAVIGACIJA
   Kreiraj header navigaciju za sve stranice.

10. FILAMENT RESOURCES
    Kreiraj PageResource s Builder poljem za blokove.
    Svaki blok tip ima svoje forme u Builderu.

11. KONFIGURACIJA
    - config/platform.php (site name, default_theme, default_locale, languages)
    - config/ai.php (tools, context)
    - .env updates (APP_NAME, DB connection)

12. CLAUDE.md
    Kopiraj ili kreiraj CLAUDE.md u project root s Tessera konvencijama.

13. NPM
    - npm install
    - Konfiguriraj Tailwind 4 (app.css)
    - npm run build

VAZNO:
- Svaki fajl MORA imati declare(strict_types=1)
- Koristi typed properties i return types svugdje
- NE koristi Lorem ipsum — sadrzaj mora biti relevantan za projekt
- Provjeri da php artisan migrate radi bez gresaka
- Provjeri da se stranice renderiraju

JAVI NA KRAJU:
- Popis svega sto si napravio
- Kako pokrenuti dev server (php artisan serve)
- Admin panel URL i kredencijali
- Sto junior treba vizualno provjeriti
PROMPT;
    }

    private function finalSetup(): void
    {
        // Run migrations
        Console::spinner('Pokrecem migracije...');
        Console::exec('php artisan migrate --force', $this->fullPath);

        // Build assets
        Console::spinner('Buildamo assets...');
        $npm = Console::execSilent('npm --version');

        if ($npm['exit'] === 0) {
            Console::exec('npm install', $this->fullPath);
            Console::exec('npm run build', $this->fullPath);
        }

        // Cache
        Console::exec('php artisan config:cache', $this->fullPath);
        Console::exec('php artisan route:cache', $this->fullPath);
        Console::exec('php artisan view:cache', $this->fullPath);
        Console::exec('php artisan filament:cache-components', $this->fullPath);
    }

    private function showComplete(): void
    {
        Console::line();
        Console::cyan('╔══════════════════════════════════════╗');
        Console::cyan('║         PROJEKT JE SPREMAN!          ║');
        Console::cyan('╚══════════════════════════════════════╝');
        Console::line();
        Console::line("  cd {$this->directory}");
        Console::line('  php artisan serve');
        Console::line();
        Console::line('  Stranica: http://localhost:8000');
        Console::line('  Admin:    http://localhost:8000/admin');
        Console::line('  Login:    admin@tessera.test / password');
        Console::line();
        Console::line('  Za dalje promjene koristi:');
        Console::line('    php artisan tessera "sto trebas"');
        Console::line();
    }
}
