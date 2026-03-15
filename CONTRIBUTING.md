# Contributing to Tessera Installer

## Setup

```bash
git clone https://github.com/drnasin/tessera-installer.git
cd tessera-installer
composer install
```

## Running Tests

```bash
# All tests (131 tests, zero token usage)
vendor/bin/phpunit

# Specific test file
vendor/bin/phpunit tests/Unit/AiResponseTest.php

# Specific test method
vendor/bin/phpunit --filter=testResolveComplexDefaultsToClaudeOpus
```

Tests run on Windows, macOS, and Linux via GitHub Actions.

## Code Style

We use Laravel Pint. Run before committing:

```bash
# From the parent tessera project (Pint is a dev dependency there)
../vendor/bin/pint src/ tests/
```

## Architecture

```
src/
├── AiTool.php              # Detects & executes AI CLI tools (claude, gemini, codex)
├── AiResponse.php          # Response DTO with rate limit & tool down detection
├── ToolRouter.php          # Smart per-task routing with fallback chains
├── ToolSelection.php       # Tool + model pair DTO
├── ToolPreference.php      # Plan tiers, ordering, env var configuration
├── RateLimitTracker.php    # Cooldown state machine for rate-limited tools
├── UsageTracker.php        # Call counting per tool+model
├── Complexity.php          # Enum: SIMPLE, MEDIUM, COMPLEX
├── StepRunner.php          # Step execution with AI error recovery + peer review
├── Memory.php              # Atomic state persistence for resume support
├── Console.php             # I/O, clean exec, safe helpers
├── SystemInfo.php          # OS, package manager, tool detection
├── NewCommand.php          # Main `tessera new` orchestrator
└── Stacks/
    ├── StackInterface.php  # Contract for all stacks
    ├── StackRegistry.php   # Stack discovery and AI context
    ├── LaravelStack.php    # Laravel + Filament stack
    ├── NodeStack.php       # Node.js / Next.js stack
    ├── GoStack.php         # Go backend stack
    ├── FlutterStack.php    # Flutter mobile stack
    ├── StaticStack.php     # Static site (HTML + Tailwind)
    └── Prompts/
        └── LaravelPrompts.php  # Extracted AI prompt templates
```

## Adding a New Stack

1. Create `src/Stacks/YourStack.php` implementing `StackInterface`
2. Register it in `StackRegistry::init()`
3. Add preflight checks (what tools does this stack need?)
4. Add the scaffold flow with AI steps
5. Include the universal rules in your prompts:
   - "Use it like a customer" (test in your head)
   - "Everything dynamic from database" (if applicable)
   - "Verify before you use" (read source files)

```php
final class PythonStack implements StackInterface
{
    public function name(): string { return 'python'; }
    public function label(): string { return 'Python (Django)'; }
    public function description(): string { return 'Web apps, APIs...'; }

    public function preflight(): array
    {
        $missing = [];
        $python = Console::execSilent('python3 --version');
        if ($python['exit'] !== 0) {
            $missing[] = 'Python 3.10+ (https://python.org)';
        }
        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements,
        ToolRouter $router, SystemInfo $system, Memory $memory): bool
    {
        // Your AI-driven scaffold flow here
    }

    // ... postSetup(), completionInfo()
}
```

## Key Design Decisions

**Principle-based prompts, not checklists.** AI prompts use 2-3 universal rules instead of long specific checklists. This forces the AI to reason rather than check boxes, and covers edge cases we haven't thought of.

**Version-agnostic.** No hardcoded framework versions in prompts. AI verifies namespaces and APIs against `vendor/` or installed packages. Works with any version.

**Peer review.** After theme and admin generation, a different AI tool/model reviews the output. Two different "brains" catch more mistakes than self-review.

**Post-build verification.** PHP lint, route verification, and Filament namespace auto-fix run after AI steps — catching errors without spending tokens.

**Atomic state.** Memory writes use temp file + rename to prevent corruption on crash.

**Cross-tool routing.** Each task gets the best tool+model. Rate limits trigger automatic fallback. Plans determine priority order.

## Testing Guidelines

- All tests must pass with **zero token usage** (no real AI calls)
- Use `AiTool::fake('claude')` to create test tool instances
- Use `ob_start()/ob_end_clean()` to suppress Console output in tests
- Test decision logic and state machines, not AI output quality
- New features should include tests

## Environment Variables

| Variable | Purpose | Example |
|---|---|---|
| `TESSERA_CLAUDE_PLAN` | Claude subscription plan | `max`, `pro`, `free` |
| `TESSERA_CODEX_PLAN` | Codex/OpenAI plan | `plus`, `free` |
| `TESSERA_GEMINI_PLAN` | Gemini plan | `pro`, `free` |
| `TESSERA_TOOL_PREFERENCE` | Custom tool order | `gemini,claude,codex` |
| `TESSERA_TOOL_EXCLUDE` | Tools to never use | `codex` |
| `TESSERA_AI_TIMEOUT` | AI step timeout (seconds) | `900` |
