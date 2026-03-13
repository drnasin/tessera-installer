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

That's it. AI will ask you a few questions about your project — what it does, what languages you need, what design style you want — and build everything.

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

AI: What kind of project are you building?
> A website for a restaurant in Split, needs a menu and reservations

AI: Do you need multiple languages?
> Yes, Croatian and English

AI: Would you like a designed frontend? Any style or color preferences?
> Yes, modern and warm. Earth tones — brown, cream, olive green.

AI is choosing technology...
✓ Selected: Laravel + Filament (Tessera CMS)

Continue? [Y/n]: Y

Building your project — this takes about 10-15 minutes.
  Go grab a coffee, AI is doing all the work.

[1/7] Create Laravel project
✓ [1/7] Create Laravel project
⏳ [2/7] Installing packages...
✓ Install packages
⏳ [3/7] Setting up admin panel...
✓ Filament panel setup
⏳ [4/7] Publishing configs...
⏳ [5/7] Creating project structure...
[6/7] AI is building your project — this is the big one...
  ✓ Creating database models and services
  ✓ Designing frontend theme and pages
  ✓ Building admin panel
  ✓ Writing content and seeding data
  ✓ Generating tests
  ✓ All tests passing

╔══════════════════════════════════════╗
║         PROJECT IS READY!            ║
╚══════════════════════════════════════╝

  cd my-restaurant
  php artisan serve

  Site:   http://localhost:8000
  Admin:  http://localhost:8000/admin
  Login:  admin@tessera.test / password
```

## How AI picks the technology

You don't need to know what Laravel or Flutter is. Just describe what you need:

| What you say | What AI picks |
|---|---|
| "website for a restaurant" | Laravel (CMS with admin panel) |
| "mobile app for delivery" | Flutter (iOS + Android) |
| "API for a chat app with 10,000 users" | Go (high-performance backend) |
| "SaaS dashboard with React frontend" | Node.js (Next.js + API) |
| "landing page for an event" | Static (HTML + Tailwind) |

AI looks at what you described and decides on its own. If you disagree, just say "no, I'd rather use Laravel" and AI will change its decision.

## Features

### OS Awareness
AI detects your operating system, package managers, and installed tools. Every AI prompt includes full system context — it knows if you're on Windows with scoop, macOS with brew, or Ubuntu with apt. If a dependency is missing, AI knows how to install it on your specific OS.

### Auto-Install Dependencies
If the chosen stack needs a tool you don't have (e.g., Node.js for frontend assets), AI offers to install it automatically using the right package manager for your OS.

### Design Questions
AI asks about your design preferences — style (modern, minimal, bold), colors, mood. These preferences are passed to the scaffold prompts, so the generated frontend matches what you described.

### Self-Healing Tests
After building the project, AI generates tests and runs them. If any test fails, AI analyzes the output and fixes the issue — either in the test or in the code. Up to 3 attempts.

### Project Memory
AI maintains state in `.tessera/state.json` — tracking completed steps, decisions, and notes. If something fails, AI knows exactly where it stopped and what was already done.

### Step-by-Step Progress
Clear progress indicators (`[1/7]`, `[2/7]`...) with human-friendly descriptions so you always know what's happening.

## Available Stacks

### Laravel + Filament (fully autonomous)
Websites, CMS, e-commerce, admin panels. AI sets up everything — models, migrations, theme, pages, blocks, admin resources, content, and tests.

### Node.js / Next.js
API servers, SaaS platforms, React/Vue applications. AI generates the full project structure with TypeScript, Prisma, and styled frontend.

### Go
High-performance backends, microservices, real-time systems. AI generates a project with Chi/Gin router, GORM, Docker, and tests.

### Flutter
Mobile applications (iOS + Android + Web). AI creates a project with Riverpod, go_router, Material 3 theme, and widget tests.

### Static Site
Simple landing pages without a backend. HTML + Tailwind + Alpine.js. Styled to your preferences, ready to deploy on Netlify/Vercel.

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
- `--force` — overwrites the directory if it already exists (useful for retrying)

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

# 3. Start working
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

```bash
cd my-project

# Start dev server
php artisan serve

# AI chat in terminal
php artisan tessera

# Direct request
php artisan tessera "add a gallery to the homepage"

# Fix an error
php artisan tessera --fix

# AI project review
php artisan tessera --audit
```

In the admin panel (`/admin`) there's an AI chat widget in the bottom-right corner. It tracks what you're doing and offers help.

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
