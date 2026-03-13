# Tessera Installer

Create a new project with a single command. AI decides everything — you just describe what you need.

## Installation

```bash
composer global require tessera/installer
```

Make sure the Composer `global bin` directory is in your PATH:
- **Windows:** `%APPDATA%\Composer\vendor\bin`
- **macOS/Linux:** `~/.composer/vendor/bin`

## Usage

```bash
tessera new my-project
```

That's it. AI will ask you a few questions and set everything up.

## What happens when you run `tessera new`

```
$ tessera new my-restaurant

╔══════════════════════════════════════╗
║        TESSERA — AI Architect        ║
║   Describe what you need, AI decides ║
╚══════════════════════════════════════╝

✓ AI: claude
Available stacks:
  ✓ Laravel + Filament (Tessera CMS)
  ✓ Node.js (Next.js / Express)
  ✓ Flutter (Mobile + Web App)
  ✓ Static Site (HTML + Tailwind)

AI: Hi! What kind of project are you building?
> A website for a restaurant in Split, needs a menu and reservations

AI: Got it. Do you need multiple languages?
> Yes, Croatian and English

AI is choosing technology...
✓ Selected: Laravel + Filament (Tessera CMS)

Continue? [Y/n]: Y

⏳ Creating Laravel project...
✓ Laravel project created
⏳ Installing Tessera packages...
✓ Core packages installed
⏳ AI is configuring and building the project...
✓ AI scaffold complete

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

## Available Stacks

### Laravel + Filament (fully autonomous)
Websites, CMS, e-commerce, admin panels. AI sets up everything — pages, blocks, modules, theme, SEO.

### Node.js / Next.js
API servers, SaaS platforms, React/Vue applications. AI generates the structure and starter code.

### Go
High-performance backends, microservices, real-time systems. AI generates a project with Chi router and Prisma/GORM.

### Flutter
Mobile applications (iOS + Android + Web). AI creates a project with Riverpod state management and Material 3 theme.

### Static Site
Simple landing pages without a backend. HTML + Tailwind + Alpine.js. Ready to deploy on Netlify/Vercel.

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

Optional (depends on the chosen stack):
- **Node.js 20+** — for Node.js stack and npm packages
- **Go 1.22+** — for Go stack
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

If you're a developer and want to add support for a new technology (e.g. Python/Django):

1. Create `src/Stacks/PythonStack.php` implementing `StackInterface`
2. Register it in `StackRegistry::init()`
3. AI automatically knows about the new stack

```php
final class PythonStack implements StackInterface
{
    public function name(): string { return 'python'; }
    public function label(): string { return 'Python (Django)'; }
    public function description(): string { return 'Web apps, APIs, ML...'; }
    // ... implement scaffold(), preflight(), postSetup()
}
```

## License

MIT License. See [LICENSE](LICENSE) for details.
