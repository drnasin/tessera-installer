<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks\Prompts;

/**
 * AI prompt templates for the Laravel stack.
 *
 * Extracted from LaravelStack to keep the orchestration class readable.
 * Each method returns a prompt string with variables interpolated.
 */
final class LaravelPrompts
{
    /**
     * Step A: Create core models, services, routes, and optionally the shop module.
     */
    public static function models(
        string $systemContext,
        string $memoryContext,
        string $stackVersions,
        string $desc,
        string $langs,
        string $shop,
        string $payments,
        string $country,
        string $userRequirements = '',
    ): string {
        $userReqBlock = $userRequirements !== ''
            ? "\nUSER'S SPECIFIC REQUESTS (MUST follow — the user explicitly asked for these):\n{$userRequirements}\n"
            : '';

        return <<<PROMPT
You are a SENIOR Laravel developer building a Tessera CMS project from scratch.
Think carefully about what THIS specific project needs before writing any code.

{$systemContext}

{$memoryContext}

STACK: {$stackVersions}
You are in the project root directory. Directory structure already exists.
{$userReqBlock}

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

TWO RULES THAT APPLY TO EVERYTHING YOU CREATE:
1. Before using ANY class, method, column, or config key — verify it exists by reading the source.
   Run `find` or `grep` if unsure. Never rely on memory — the installed version may differ.
2. Test every feature in your head as a user. Switch locale — does it still work?
   Click every route — does it resolve? Submit every form — does it save?
   Render every Livewire component — does it have a layout? If not, it's broken.

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
PROMPT;
    }

    /**
     * Step B: Design frontend theme, block views, and pages.
     */
    public static function theme(
        string $desc,
        string $needsFrontend,
        string $designStyle,
        string $designColors,
        string $langs,
        string $shop,
        string $userRequirements = '',
    ): string {
        $userReqBlock = $userRequirements !== ''
            ? "\nUSER'S SPECIFIC REQUESTS (MUST follow — the user explicitly asked for these):\n{$userRequirements}\n"
            : '';

        return <<<PROMPT
CONTINUE working on the Tessera project. Models, migrations, controllers, and services are already created.
Read them to understand the data structure before creating views.
Read the Block model and BlockRegistry to understand the data flow.
{$userReqBlock}

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
- AFFORDANCE: Interactive elements must LOOK interactive. Buttons look like buttons.
- FEEDBACK: Every user action gets immediate visual feedback.
- CONSISTENCY: Same patterns everywhere. Same spacing, same typography scale, same color meanings.
- ACCESSIBILITY: Sufficient color contrast (WCAG AA minimum). Focus states on interactive elements.
- MOBILE-FIRST: Design for phone screens first, enhance for desktop. Touch targets minimum 44px.
- WHITESPACE: Generous spacing creates clarity. Let content breathe.
- NAVIGATION: The user must ALWAYS know where they are and how to get elsewhere.

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

   Create blocks appropriate for THIS project. Think about what the business needs.

3. IF E-COMMERCE: Shop views must share the same header, footer, and visual style as CMS pages.

4. Routing: catch-all /{slug?} in bootstrap/app.php (AFTER Filament routes, in 'then:' callback)

5. config/platform.php — site_name, default_theme, supported_locales, address, contact_phone, contact_email, social links

6. NULL-SAFE CONTENT: All blade views must handle null/empty data gracefully. Use null coalescing
   (\$block->data['key'] ?? '') for all data access.

7. MULTILINGUAL: All hardcoded text in templates must be locale-aware for all configured languages.

DESIGN — You are designing a REAL website for a REAL business, not a code demo.
Before writing any Tailwind class, ask: "Would a client pay for this? Would users enjoy using it?"

Style: {$designStyle}
Colors: {$designColors}
Content language: {$langs}
Images: use curator_url(\$block->data['image']) helper for all media

TWO RULES THAT PREVENT ALL COMMON MISTAKES:

1. USE IT LIKE A CUSTOMER.
   After writing each file, open the page in your head as a first-time visitor.
   Click every link — does it go somewhere real? Read every text — can you see it?
   Fill out every form — does it submit? Browse on a phone — does it work?
   If something is broken, invisible, or confusing — fix it before moving on.

2. EVERYTHING DYNAMIC COMES FROM THE DATABASE.
   If an admin might want to change it — it must NOT be in code.
   Navigation links, footer links, page content, business info, colors, labels —
   all from the database via models (Navigation, Page, Block) or config files.
   The only hardcoded URLs are framework routes (/shop, /admin, /).
   If you write href="/about" in a blade file — that is WRONG. Use the Navigation model.
PROMPT;
    }

    /**
     * Step C: Build Filament admin panel with resources for all models.
     */
    public static function admin(string $desc, string $shop): string
    {
        return <<<PROMPT
CONTINUE working on the Tessera project. Models, theme, and block views are already created.

CRITICAL TASK: Read EVERY block blade view you created in resources/views/themes/default/blocks/.
Look at the data key comments at the top of each file. The admin Builder MUST create form fields
that match EXACTLY those data keys.

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
  the admin an at-a-glance overview of their business.

CREATE:
1. A Filament Resource for EVERY model that holds business data.

2. PageResource — this one is special because it has the block Builder.
   Read EVERY block blade view in resources/views/themes/default/blocks/.
   The admin Builder MUST create form fields that match EXACTLY those data keys.

3. Dashboard Widgets — create widgets appropriate for THIS project's functionality.

4. CLAUDE.md — project documentation for AI assistants (structure, commands, conventions).

PROJECT: {$desc}
E-COMMERCE: {$shop}

QUALITY STANDARDS:
- Every block type the frontend uses MUST have matching admin form fields.
  The data keys in the blade view and the form field names MUST be identical.
  If a blade view uses \$block->data['heading'] and \$block->data['cta_text'],
  the admin Builder MUST have TextInput::make('heading') and TextInput::make('cta_text').
- Every business value that exists in the system must be editable by the admin.
  If the admin cannot change it without a developer, it's a bug.
- If you're unsure whether the admin needs a particular feature — they do.
  A good admin panel is COMPLETE. The admin should never need to touch code or config files.

TWO RULES:
1. Before using ANY name (column, relationship, key, class) — READ the source file where it's defined.
   Never assume. Open the migration, model, or blade view and match the exact name.
2. Test it in your head: open each admin page, click each button, fill each form.
   Does it work? Does it save? Does the data appear on the frontend?
PROMPT;
    }

    /**
     * Step D: Create content seeders and populate the database.
     */
    public static function content(string $desc, string $langs, string $shop): string
    {
        return <<<PROMPT
CONTINUE working on the Tessera project. Models, views, admin, and block views are all set up.

PROJECT: {$desc}
LANGUAGES: {$langs}
E-COMMERCE: {$shop}

THINK: What pages does THIS specific business need?
A restaurant needs: Home, Menu, About, Reservations, Contact.
A shop needs: Home, Products, About, Contact, FAQ, Shipping Info.
A portfolio needs: Home, Projects, About, Contact.
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
   - IF E-COMMERCE: seed products, categories, and demo data

2. Run: php artisan migrate --force && php artisan db:seed --force
   Verify they complete without errors.

CONTENT:
- Write REALISTIC content for this business — NO lorem ipsum, NO placeholder text
- Content language: {$langs}. ALL text must be in the configured languages.
- Use professional, compelling copy. Write like a copywriter, not a developer.
- If multilingual, both languages must have real content (not just the primary language).
PROMPT;
    }

    /**
     * Step F: Generate SETUP.md for developer handoff.
     */
    public static function setupMd(string $desc, string $shop, string $payments, string $country): string
    {
        return <<<PROMPT
Read the entire project you just built. Generate a SETUP.md file in the project root.

PROJECT: {$desc}
E-COMMERCE: {$shop}
PAYMENT PROVIDERS: {$payments}
COUNTRY: {$country}

SETUP.md must include:

1. QUICK START — step-by-step from clone to running site
2. ENVIRONMENT VARIABLES — list EVERY .env key that needs attention:
   - What each key is for
   - WHERE to get the value (with exact URLs to dashboards/signup pages)
   - Example values that show the format
3. ADMIN PANEL — URL, default credentials
4. IF E-COMMERCE:
   - Payment setup for each provider (step-by-step with links)
   - Webhook URL to configure
   - Test mode vs live mode credentials
   - Production switch checklist
5. DEPLOYMENT — server requirements, Nginx config, SSL, queue workers
6. PRODUCTION CHECKLIST — security, .env, APP_DEBUG=false, caching, backups
7. COMMON TASKS — how to add a page, block, product, language

Write for a JUNIOR developer who has never deployed a Laravel project.
Explain concepts briefly when they come up. Include actual terminal commands.
PROMPT;
    }
}
