# Tessera Installer

Create a new project with a single command. AI decides everything — you just describe what you need.

## Installation

```bash
composer global require tessera/installer
```

Make sure the Composer `global bin` directory is in your PATH:
- **Windows:** `%APPDATA%\Composer\vendor\bin`
- **macOS/Linux:** `~/.composer/vendor/bin`

Verify it works:
```bash
tessera --version
```

## Quick Start

```bash
tessera new my-project
```

That's it. AI will ask you about your project — what it does, what languages, what payment provider, what design style — and build everything.

## System Check

Before creating a project, check if your system is ready:

```bash
tessera doctor
```

```
  TESSERA DOCTOR — System Check

System:
  OS: windows
  Package manager: scoop

Required:
✓ PHP — PHP 8.5.2
✓ Composer — 2.9.2

AI tools (need at least one):
✓ claude — 2.1.75
✓ gemini — 0.32.1
✓ codex — 0.98.0

Optional (depends on stack):
✓ Node.js — v25.8.0
✓ Go — go1.25.0
  Flutter — not installed
✓ Docker — 28.4.0
✓ Git — 2.45.1

✓ Disk space: 86.3 GB free

  All good! Run: tessera new my-project
```

## What happens when you run `tessera new`

```
$ tessera new my-restaurant

╔══════════════════════════════════════╗
║        TESSERA — AI Architect        ║
║   Describe what you need, AI decides ║
╚══════════════════════════════════════╝

✓ AI: claude
✓ OS: windows (scoop)

AI: Tell me about the project — what does the client do?
> A restaurant in Split, they need a website with a menu and online reservations

AI: Which languages should the site support?
> Croatian and English

AI: Will customers be paying online, or is this more of a presentation site?
> Just presentation, no payments. But they want a reservation form.

AI: Would you like a designed frontend? What style — modern, rustic, elegant?
> Modern and warm. Earth tones — brown, cream, olive green.

AI: Got it! How many pages do you expect — just a few key ones, or a larger site?
> 4-5 pages: home, menu, about us, gallery, contact

AI is choosing technology...
✓ Selected: Laravel + Filament (Tessera CMS)

Continue? [Y/n]: Y

Building your project — this takes about 10-15 minutes.
  Go grab a coffee, AI is doing all the work.

[1/8] Create Laravel project
✓ [1/8] Create Laravel project
⏳ [2/8] Installing packages...
✓ Install packages
⏳ [3/8] Setting up admin panel...
✓ Filament panel setup
⏳ [4/8] Publishing configs...
⏳ [5/8] Creating project structure...
[6/8] AI is building your project — this is the big one...
  ✓ Creating database models and services
  ✓ Designing frontend theme and pages
  ✓ Building admin panel
  ✓ Writing content and seeding data
  ✓ Generating tests
  ✓ All tests passing
  ✓ Generating setup instructions for developer

╔══════════════════════════════════════╗
║         PROJECT IS READY!            ║
╚══════════════════════════════════════╝

  IMPORTANT: Read SETUP.md for configuration steps!

  cd my-restaurant
  php artisan serve

  Site:   http://localhost:8000
  Admin:  http://localhost:8000/admin
  Login:  admin@tessera.test / password
  Setup guide: SETUP.md
```

## How AI thinks

AI doesn't just follow a recipe. It reasons about your project like a senior developer:

| What you say | What AI thinks | What it builds |
|---|---|---|
| "restaurant with a menu" | CMS with pages, menu block type, reservation form | Laravel + custom blocks |
| "web shop for bikes" | E-commerce! Products, cart, checkout, payment gateway | Laravel + full shop module |
| "delivery app" | Mobile! Needs GPS, push notifications, real-time tracking | Flutter + Firebase |
| "API for 10K concurrent users" | High-performance, needs WebSocket, rate limiting | Go + Chi + PostgreSQL |
| "landing page for conference" | Simple, no backend, SEO important, deploy to CDN | Static + Tailwind + Vite |

AI detects signals: if you mention "shop", "products", "selling", "booking with payment" — it knows that's e-commerce and will ask about payment providers, country, shipping.

## Features

### Senior Dev Reasoning
AI doesn't blindly scaffold. Before writing code, it thinks: "What entities does this business need? What pages? Does it need user accounts? What data flows between admin and frontend?" Then it builds exactly what the project requires.

### Smart Conversation
AI asks about 5 mandatory topics before building:
1. **Business** — what the client does, what problem we're solving
2. **Languages** — which languages the site needs (affects DB structure, routing, UI)
3. **Payments** — if e-commerce: which provider? Country-aware suggestions
4. **Frontend design** — style, colors, mood preferences
5. **Scale** — expected products/pages/users

If something is unclear, AI asks — it never assumes.

### Country-Aware Payment Providers
AI knows which payment providers are popular in each country:

| Country | Suggested providers |
|---|---|
| Croatia, Slovenia, Serbia | CorvusPay, WSPay, Stripe |
| Austria, Germany, Switzerland | Klarna, Mollie, Stripe, PayPal |
| UK | Stripe, GoCardless, PayPal |
| USA | Stripe, Square, PayPal |
| Other | Stripe + local provider |

### Frontend-Admin Wiring
When AI creates block views for the frontend, it documents the data keys each block expects. Then it creates matching admin forms — so what you edit in admin is exactly what appears on the website. No disconnects.

### SETUP.md — Developer Handoff
After building the project, AI generates a `SETUP.md` with everything the developer needs:
- **Environment variables** — exact key names, what they are, WHERE to get them (with URLs)
- **Payment setup** — step-by-step: create account, get API keys, test credentials, webhook URL
- **Production checklist** — security, database, email, SSL, caching
- **Common tasks** — how to add a page, block, language, payment provider

Written for junior developers — explains technical concepts when needed.

### OS Awareness
AI detects your operating system, package managers, and installed tools. Every AI prompt includes full system context — it knows if you're on Windows with scoop, macOS with brew, or Ubuntu with apt.

### Auto-Install Dependencies
If the chosen stack needs a tool you don't have (e.g., Node.js for frontend assets), AI offers to install it automatically using the right package manager for your OS.

### Self-Healing Tests
After building the project, AI generates tests and runs them. If any test fails, AI analyzes the output and fixes the issue — either in the test or in the code. Up to 3 attempts.

### Project Memory & Resume
AI maintains state in `.tessera/state.json` — tracking completed steps, decisions, and notes. If a build fails or times out, progress is saved. Run the same command again and AI offers to **resume from where it stopped** — no need to re-describe the project.

```
$ tessera new my-shop
# ... fails on step 5 ...
# Progress saved!

$ tessera new my-shop
# Detected previous install. What would you like to do?
# [0] Resume — continue from where it stopped
# [1] Start fresh — delete and rebuild
# [2] Abort

> 0
Resuming build — skipping completed steps...
✓ [1/8] Create Laravel project (already done)
✓ [2/8] Install packages (already done)
✓ [3/8] Setting up admin panel (already done)
✓ [4/8] Publishing configs (already done)
⏳ [5/8] Creating project structure...
```

## Available Stacks

### Laravel + Filament (fully autonomous)
Websites, CMS, e-commerce, admin panels. AI sets up everything — models, migrations, theme, pages, blocks, admin resources, content, tests, and SETUP.md with configuration instructions.

### Node.js / Next.js
API servers, SaaS platforms, React/Vue applications. AI generates the full project structure with TypeScript, Prisma, Docker, and styled frontend. SETUP.md includes API docs and deployment instructions.

### Go
High-performance backends, microservices, real-time systems. AI generates a project with Chi/Gin router, GORM, Docker, structured logging, and health checks. SETUP.md includes curl examples for every endpoint.

### Flutter
Mobile applications (iOS + Android + Web). AI creates a project with Riverpod, go_router, Material 3 theme, and widget tests. SETUP.md includes build/deploy instructions for each platform.

### Static Site
Simple landing pages without a backend. HTML + Tailwind + Alpine.js. Professional quality — AI writes like a copywriter, not a template engine. SETUP.md includes deployment to Netlify/Vercel/GitHub Pages.

## Commands

### `tessera doctor` — "Am I ready?"

Run this **first**, before anything else. It checks your system — PHP, Composer, AI tools, Node.js, disk space — and tells you what's missing. Think of it as a health check.

```bash
tessera doctor
```

**When to use:**
- Before your first `tessera new` — to make sure everything is installed
- When `tessera new` fails — to see if something is missing or broken
- On a new machine — to quickly see what you need to install

### `tessera new {name}` — "Build me a project"

This is the main command. AI asks you about the project, picks the technology, and builds everything.

```bash
tessera new my-shop
```

**When to use:**
- Every time you start a new project for a client
- You describe what the client needs, AI does the rest

**Options:**
- `--force` — overwrites the directory if it already exists
- If the directory has a previous `.tessera/state.json`, you'll be offered to **resume** instead

### `tessera tools` — "Which AI tools do I have?"

Shows which AI CLI tools (Claude, Codex, Gemini) are installed. The first one found is used for scaffolding.

```bash
tessera tools
```

**When to use:**
- If you're not sure which AI tool Tessera will use
- After installing a new AI tool, to verify it's detected

### `tessera --version`

Shows the installed version.

```bash
tessera --version
```

### Typical workflow

```bash
# 1. Check your system (first time only)
tessera doctor

# 2. Create a project
tessera new my-restaurant

# 3. Read the setup guide
cat my-restaurant/SETUP.md

# 4. Configure (API keys, payments, email)
# ... follow SETUP.md instructions ...

# 5. Start working
cd my-restaurant
php artisan serve
```

## Prerequisites

Required:
- **PHP 8.2+** — `php --version`
- **Composer** — `composer --version`
- **AI CLI tool** — at least one of:

| Tool | Installation | Check |
|---|---|---|
| Claude | `npm install -g @anthropic-ai/claude-code` | `claude --version` |
| Codex | `npm install -g @openai/codex` | `codex --version` |
| Gemini | `npm install -g @google/gemini-cli` | `gemini --version` |

Optional (auto-installed if missing):
- **Node.js** — for frontend assets and Node.js stack
- **Go** — for Go stack
- **Flutter SDK** — for Flutter stack

## After creating a project

How you make changes depends on which stack was chosen:

### Laravel (Tessera CMS)

Laravel projects have a built-in AI Engine with full project awareness — it reads models, blocks, theme, admin, and knows the entire architecture.

```bash
cd my-project

# Read setup instructions FIRST
cat SETUP.md

# Start dev server
php artisan serve

# AI chat — describe what you need
php artisan tessera

# Direct request
php artisan tessera "add a gallery to the homepage"

# Fix an error
php artisan tessera --fix

# AI project review
php artisan tessera --audit
```

For content changes (text, images, pages) — use the admin panel at `/admin`. There's an AI chat widget in the bottom-right corner that tracks what you're doing and offers help.

For structural changes (new modules, block types, integrations) — use `php artisan tessera`.

### Node.js / Go / Flutter / Static

These stacks don't have a built-in AI Engine. Use your AI CLI tool directly — it reads the project and knows the structure:

```bash
cd my-project

# Read setup instructions FIRST
cat SETUP.md

# Use your AI CLI tool directly
claude "add user authentication with JWT"
# or
codex "create REST API for products"
# or
gemini "add dark mode toggle"
```

The AI reads the codebase and understands the project structure, so you don't need to explain the architecture — just describe what you need.

## Adding a new stack

Create `src/Stacks/PythonStack.php` implementing `StackInterface`, register it in `StackRegistry::init()`, and AI automatically knows about it.

```php
final class PythonStack implements StackInterface
{
    public function name(): string { return 'python'; }
    public function label(): string { return 'Python (Django)'; }
    public function description(): string { return 'Web apps, APIs, ML...'; }
    // ... implement scaffold(), preflight(), postSetup(), completionInfo()
}
```

## License

MIT License. See [LICENSE](LICENSE) for details.
