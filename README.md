# Tessera Installer

[![Tests](https://github.com/drnasin/tessera-installer/actions/workflows/tests.yml/badge.svg)](https://github.com/drnasin/tessera-installer/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-Source--available-blue)](LICENSE.md)

Website: **[tessera-ai.net](https://tessera-ai.net)** · Docs: [tessera-ai.net/docs](https://tessera-ai.net/docs/what-is-tessera)

> Describe your project. AI builds it.
>
> One command, one conversation, one production-ready codebase. Tessera turns the AI CLI tools you already have (Claude, Codex, Gemini) into an inspectable, resumable build orchestrator.

> **Important:** Tessera uses **your own** AI CLI tools and **your own** subscription. Each call burns tokens from your plan. Generated code may contain bugs, security issues, or wrong implementations. Always review before shipping. See [Disclaimer](#disclaimer).

## Install in 30 seconds

```bash
composer global require tessera/installer
```

Make sure the Composer global bin directory is in your `PATH`:

| OS | Path |
|---|---|
| Windows | `%APPDATA%\Composer\vendor\bin` |
| macOS / Linux | `~/.composer/vendor/bin` |

```bash
tessera --version
tessera doctor    # check that your system is ready
```

## Build your first project

```bash
tessera new my-restaurant
```

Tessera asks five questions (business, languages, payments, design, scale), picks the right stack, and runs the AI build. Coffee break — the typical Laravel build is 10–15 minutes.

[Full walkthrough →](https://tessera-ai.net/docs/creating-project)

## See what AI is about to do

Before you spend a single token, compile and inspect the build plan:

```bash
tessera plan compile stacks/laravel.yaml
tessera plan show          # pretty-print every step
tessera plan diff a.json b.json    # what changed?
```

Every plan is hash-anchored. Tweak a prompt, recompile, diff — you see exactly what shifted. [`tessera plan` reference →](https://tessera-ai.net/docs/cli/plan)

## Available stacks

| Stack | Best for | Where the recipe lives |
|---|---|---|
| **Laravel + Filament** | CMS, e-commerce, admin panels | [`stacks/laravel.yaml`](stacks/laravel.yaml) |
| **Node.js / Next.js** | API servers, SaaS, real-time apps | [`stacks/node.yaml`](stacks/node.yaml) |
| **Go** | High-performance backends, microservices | [`stacks/go.yaml`](stacks/go.yaml) |
| **Flutter** | Mobile (iOS + Android + web) | [`stacks/flutter.yaml`](stacks/flutter.yaml) |
| **Static** | Landing pages, portfolios, event sites | [`stacks/static.yaml`](stacks/static.yaml) |

Every stack is described in YAML. The same engine drives them all. [Authoring a custom stack →](https://tessera-ai.net/docs/architecture/yaml-manifests)

## What you get out of the box

- **Inspectable plans.** `tessera plan compile/show/diff` — no tokens spent until you say go.
- **Quality gates.** Each step declares post-checks (`exists_any`, `exists_all`) so the AI saying "done!" against an empty directory fails loudly.
- **Skippable enrichment.** Polish steps and SETUP.md generators that hit a transient rate limit don't abort a 25-minute build.
- **Build trace.** Every run leaves `.tessera/state.json` + `.tessera/events.jsonl` + `.tessera/plan.json`. Post-mortem any build with `jq` and a few seconds.
- **Resume.** Crash, Ctrl+C, network drop — run the same command, pick up where it left off. Atomic state writes survive interrupts.
- **Cross-OS process control.** Windows `taskkill /F /T`, Unix `pgrep -P` + `posix_kill`. Tessera never leaves zombie AI subprocesses pinned to your terminal.
- **Plan-aware AI routing.** Claude Max gets all the steps; free-tier accounts hit fallback chains. Rate limits cause a 2-minute cooldown and a tool switch, not a build crash.
- **AI tool isolation.** Each adapter sees only its own credentials — `ANTHROPIC_API_KEY` never reaches Codex; `OPENAI_API_KEY` never reaches Claude.
- **Pluggable adapters.** [Adding Groq, Ollama, or your own AI →](https://tessera-ai.net/docs/architecture/adapter-system)

## Quick reference — CLI commands

```bash
tessera doctor                                       # is my system ready?
tessera new <dir>                                    # the main command
tessera new <dir> --force                            # overwrite existing dir
tessera new <dir> --stack=<name>                     # skip AI stack selection
tessera new <dir> --requirements-fixture=<path>      # skip interactive Q&A (loads JSON)
tessera plan compile <yaml> [-o <out.json>]          # compile manifest → plan.json
tessera plan show [<plan.json>]                      # pretty-print a plan
tessera plan diff <a.json> <b.json>                  # semantic diff (exit 0/1/2)
tessera tools                                        # which AI tools are installed?
tessera --version
tessera --help
```

[Full CLI reference on the docs site →](https://tessera-ai.net/docs/cli/plan)

## Environment variables

Optional. The installer asks interactively if not set.

```bash
TESSERA_CLAUDE_PLAN=max       # max | pro | free
TESSERA_CODEX_PLAN=plus       # plus | free
TESSERA_GEMINI_PLAN=free      # pro | free
TESSERA_TOOL_PREFERENCE=gemini,claude,codex   # custom tool order
TESSERA_TOOL_EXCLUDE=codex                    # never use this tool
TESSERA_SAFE_AI=1             # opt out of --dangerously-skip-permissions for Claude
TESSERA_AI_TIMEOUT=900        # seconds per AI step (default 900)
```

[Full security & permission model →](https://tessera-ai.net/docs/disclaimer)

## After the build

```bash
cd my-restaurant
cat SETUP.md              # what to configure (env vars, API keys, deploy)
php artisan serve          # for Laravel — npm run dev for static/node, etc.
```

Each stack's `SETUP.md` is junior-developer-friendly: env keys with the URL where to get them, payment provider step-by-step, deployment checklist. AI writes it as part of the build.

For continuing changes:

- **Laravel** — `php artisan tessera "add a gallery to the homepage"` (in-project AI Engine with project context).
- **Other stacks** — use your AI CLI directly: `claude "add JWT auth"` / `codex "create REST endpoints"` / `gemini "add a dark mode toggle"`. The AI reads your codebase and knows the structure.

## Testing

```bash
vendor/bin/phpunit
```

406 tests, 801 assertions — all passing with zero AI tokens. CI runs on Ubuntu, Windows, and macOS with PHP 8.4–8.5.

## Contributing & adding a stack

[Contributing guide →](https://tessera-ai.net/docs/contributing) · [How to author a stack →](https://tessera-ai.net/docs/architecture/yaml-manifests)

The minimal recipe: drop `stacks/<your-stack>.yaml`, add a `*Stack.php` lifecycle class with `preflight()` / `postSetup()` / `completionInfo()`, register in `StackRegistry`. The YAML drives the AI portion; the PHP class only handles tool-specific lifecycle.

## Disclaimer

**AI Token Usage.** Tessera calls AI CLI tools installed on your system. Each call consumes tokens from your own subscription plan or API quota. Tessera does not provide, manage, or pay for AI access. A typical project build uses 5–10 AI calls.

**Generated Code.** AI-generated code may contain bugs, security vulnerabilities, incorrect business logic, or incompatible dependencies. Tessera includes safeguards (quality gates, PHP lint, test loops, namespace verification), but you are responsible for reviewing, testing, and validating all generated code before production.

**Third-Party Services.** Tessera may scaffold integrations with third-party services (payment providers, cloud APIs, email services). These integrations need manual configuration and validation. Tessera is not affiliated with and makes no guarantees about any third-party service.

**No Warranty.** This software is provided "as is", without warranty of any kind. The authors are not liable for damages arising from the use of this software or any code it generates. Use at your own risk.

[Full disclaimer →](https://tessera-ai.net/docs/disclaimer)

## License

Tessera is **source-available**, not open source.

| Use case | Cost | Licence |
|---|---|---|
| Personal projects, learning, OSS, non-profit, government | **Free** | [PolyForm Noncommercial 1.0.0](LICENSE.md) |
| Client work for pay (agency / freelance) | from **€249/yr** | Commercial — Solo |
| Agency / small product team (up to 10 devs) | **€799/yr** | Commercial — Studio |
| Larger teams, custom SLA, private registry | **Custom** | Commercial — Enterprise |

**Generated code is yours** in either lane — the licence covers the installer itself, not the projects it scaffolds.

- [Pricing & buy a commercial licence →](https://tessera-ai.net/docs/pricing)
- [Full Commercial License Agreement →](https://tessera-ai.net/docs/commercial-license)
- [Licence overview & FAQ →](https://tessera-ai.net/docs/license)
