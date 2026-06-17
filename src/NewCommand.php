<?php

declare(strict_types=1);

namespace Tessera\Installer;

use Tessera\Installer\Cli\ProjectDirectoryName;
use Tessera\Installer\Plan\PromptRenderer;
use Tessera\Installer\Stacks\StackInterface;
use Tessera\Installer\Stacks\StackRegistry;

/**
 * `tessera new {directory}` — AI-powered project scaffolding.
 *
 * Flow:
 * 1. Detect AI tools + available stacks
 * 2. AI-driven conversation with junior dev
 * 3. AI decides technology stack + architecture
 * 4. Stack driver handles everything else
 */
final class NewCommand
{
    private string $directory;

    private string $fullPath;

    private ?ToolRouter $router = null;

    private SystemInfo $system;

    private ?Memory $memory = null;

    private bool $force;

    private ?string $forcedStack;

    private ?string $requirementsFixturePath;

    /**
     * Test-only seam: a pre-supplied first interview question that bypasses the
     * opening AI call in gatherRequirements(). Lets unit tests drive the
     * re-prompt loop deterministically without a live AI tool or router. NOT
     * wired into the bin/tessera dispatch — production always asks the AI.
     */
    private ?string $interviewFirstQuestion;

    public function __construct(
        string $directory,
        bool $force = false,
        ?string $forcedStack = null,
        ?string $requirementsFixturePath = null,
        ?string $interviewFirstQuestion = null,
    ) {
        $directoryError = ProjectDirectoryName::validate($directory);
        if ($directoryError !== null) {
            throw new \InvalidArgumentException('Invalid directory name: '.$directoryError);
        }

        $this->directory = $directory;
        $this->fullPath = getcwd().DIRECTORY_SEPARATOR.$directory;
        $this->force = $force;
        $this->forcedStack = $forcedStack;
        $this->requirementsFixturePath = $requirementsFixturePath;
        $this->interviewFirstQuestion = $interviewFirstQuestion;
        $this->system = SystemInfo::detect();
    }

    public function run(): int
    {
        // Validate --stack BEFORE any prompt, system check, or AI call. An
        // unknown forced stack must fail fast — otherwise the full wizard runs
        // (plan-tier questions + the token-burning AI interview) only to fall
        // back to AI selection in buildProject(). The resume path passes the
        // saved stack straight to buildProject() and is intentionally not
        // routed through this guard.
        if ($this->forcedStack !== null && StackRegistry::get($this->forcedStack) === null) {
            $available = implode(', ', array_keys(StackRegistry::all()));
            Console::error("Unknown stack '{$this->forcedStack}'. Available stacks: {$available}");

            return 1;
        }

        $this->showBanner();
        $this->showFirstRunNotice();

        // Step 1: Preflight checks
        if (! $this->preflight()) {
            return 1;
        }

        // Step 2: Check directory — resume or overwrite?
        if (is_dir($this->fullPath)) {
            $resumeResult = $this->handleExistingDirectory();

            if ($resumeResult !== null) {
                return $resumeResult;
            }
            // resumeResult === null means: directory is clean, continue with fresh install
        }

        // Step 3: AI-driven conversation OR --requirements-fixture
        if ($this->requirementsFixturePath !== null) {
            $requirements = $this->loadRequirementsFixture($this->requirementsFixturePath);
            if ($requirements === null) {
                return 1;
            }
        } else {
            $requirements = $this->gatherRequirements();
            if ($requirements === null) {
                return 1;
            }
        }

        return $this->buildProject($requirements, $this->forcedStack);
    }

    /**
     * Load a JSON requirements fixture instead of running interactive Q&A.
     *
     * @return array<string, mixed>|null
     */
    private function loadRequirementsFixture(string $path): ?array
    {
        if (! is_file($path)) {
            Console::error("Requirements fixture not found: {$path}");

            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            Console::error("Could not read fixture: {$path}");

            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            Console::error("Fixture is not valid JSON: {$path}");

            return null;
        }

        Console::success("Using requirements fixture: {$path}");

        return $decoded;
    }

    private function showBanner(): void
    {
        Console::line();
        Console::cyan('╔══════════════════════════════════════╗');
        Console::cyan('║        TESSERA — AI Architect         ║');
        Console::cyan('║   Describe what you need, AI decides  ║');
        Console::cyan('╚══════════════════════════════════════╝');
        Console::line();
    }

    /**
     * Show disclaimer notice on first run only.
     * Persisted via a marker file in user's home directory.
     */
    private function showFirstRunNotice(): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';

        if ($home === '') {
            return;
        }

        $markerDir = $home.DIRECTORY_SEPARATOR.'.tessera';
        $markerFile = $markerDir.DIRECTORY_SEPARATOR.'.notice-accepted';

        if (is_file($markerFile)) {
            return;
        }

        Console::warn('Notice: Tessera uses your AI CLI tools (Claude, Gemini, Codex) to generate code.');
        Console::warn('Each AI call consumes tokens from YOUR subscription plan — Tessera does not');
        Console::warn('provide or pay for AI access. Generated code is AI-produced and should be');
        Console::warn('reviewed before production use. Use at your own risk.');
        Console::line();
        Console::warn('Security: AI runs with full filesystem/shell access by default (required for');
        Console::warn('non-interactive scaffolding). Set TESSERA_SAFE_AI=1 for per-action approval.');
        Console::line();
        Console::line('Full disclaimer: https://tessera-ai.net/docs/disclaimer');
        Console::line();

        if (! Console::confirm('I understand, continue')) {
            exit(0);
        }

        Console::line();

        // Save marker so we don't show again
        if (! is_dir($markerDir)) {
            @mkdir($markerDir, 0755, true);
        }

        @file_put_contents($markerFile, date('Y-m-d H:i:s'));
    }

    private function preflight(): bool
    {
        // Check AI tools
        $preference = $this->resolveToolPreference();

        $this->router = ToolRouter::detect($preference);

        if ($this->router === null) {
            Console::error('No AI tool found!');
            Console::line('Install at least one:');
            Console::line('  - claude: https://docs.anthropic.com/en/docs/claude-code');
            Console::line('  - gemini: https://ai.google.dev/gemini-api/docs/cli');
            Console::line('  - codex:  https://github.com/openai/codex');

            return false;
        }

        $toolNames = implode(', ', $this->router->availableNames());
        Console::success("AI: {$toolNames}");
        Console::success("OS: {$this->system->os()} ({$this->system->packageManager()})");

        $dbNames = array_keys($this->system->databases());
        Console::success('DB: '.(empty($dbNames) ? 'SQLite (built-in)' : implode(', ', $dbNames)));

        // Show intelligent routing
        Console::line();
        Console::bold('AI routing:');
        Console::line($this->router->describe());

        // Show available stacks
        $available = StackRegistry::available();
        $all = StackRegistry::all();

        Console::line();
        Console::bold('Available stacks:');

        foreach ($all as $name => $stack) {
            $check = $stack->preflight();
            if ($check['ready']) {
                Console::success($stack->label());
            } else {
                Console::line("  \033[90m{$stack->label()} — missing: ".implode(', ', $check['missing'])."\033[0m");
            }
        }

        Console::line();

        if (empty($available)) {
            Console::error('No stack is ready. Install the required tools.');

            return false;
        }

        return true;
    }

    /**
     * Handle existing directory: offer resume, overwrite, or abort.
     *
     * @return int|null Null if directory was cleaned and we should continue fresh.
     */
    private function handleExistingDirectory(): ?int
    {
        $stateFile = $this->fullPath.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'state.json';

        // Check if there's a previous install we can resume
        if (is_file($stateFile)) {
            $stateContent = file_get_contents($stateFile);
            $state = $stateContent !== false ? json_decode($stateContent, true) : null;

            if (is_array($state) && ! empty($state['requirements'])) {
                $stackName = $state['stack'] ?? 'unknown';
                $status = $state['status'] ?? 'unknown';
                $completedSteps = $state['completed_steps'] ?? [];
                $completedCount = count($completedSteps);

                Console::line();
                Console::bold("Found previous installation (stack: {$stackName}, status: {$status})");

                if ($completedCount > 0) {
                    Console::line("  Completed steps: {$completedCount}");
                    foreach ($completedSteps as $step) {
                        Console::success("    {$step['name']}");
                    }
                }

                if (! empty($state['failed_steps'])) {
                    foreach ($state['failed_steps'] as $fail) {
                        Console::fail("    {$fail['name']}: {$fail['error']}");
                    }
                }

                Console::line();
                $choice = Console::choice('What would you like to do?', [
                    'Resume — continue from where it stopped (no need to re-describe the project)',
                    'Start fresh — overwrite everything',
                    'Abort',
                ]);

                if ($choice === 2) {
                    Console::warn('Aborted.');

                    return 0;
                }

                if ($choice === 0) {
                    // Resume with saved requirements — skip stack selection
                    Console::success('Resuming previous installation...');

                    return $this->buildProject($state['requirements'], $state['stack'] ?? null);
                }

                // Choice 1: start fresh — fall through to remove directory
            }
        }

        // No state file, or user chose to start fresh
        if (! $this->force) {
            Console::error("Directory '{$this->directory}' already exists.");
            Console::line('  Use --force to overwrite, or resume if a previous install exists.');

            return 1;
        }

        Console::warn("Directory '{$this->directory}' exists. Overwriting (--force)...");
        self::removeDirectory($this->fullPath);

        return null; // Continue with fresh install
    }

    /**
     * Build the project: decide stack, check deps, scaffold.
     *
     * @param  array<string, mixed>  $requirements
     */
    private function buildProject(array $requirements, ?string $resumeStack = null): int
    {
        // On resume OR with --stack=name, use the saved/forced stack — no need to ask AI.
        $forcedStack = $resumeStack ?? $this->forcedStack;

        if ($forcedStack !== null) {
            $stack = StackRegistry::get($forcedStack);

            if ($stack === null) {
                Console::warn("Stack '{$forcedStack}' not found. Falling back to AI selection...");
                $stack = $this->decideStack($requirements);
            }
        } else {
            $stack = $this->decideStack($requirements);
        }

        if ($stack === null) {
            return 1;
        }

        // Only ask for confirmation on fresh installs without a forced stack.
        if ($resumeStack === null && $this->forcedStack === null) {
            Console::line();
            Console::bold("AI recommends: {$stack->label()}");
            Console::line();

            if (! Console::confirm('Continue?')) {
                Console::warn('Cancelled.');

                return 0;
            }
        } else {
            Console::line("  Resuming with: {$stack->label()}");
        }

        // Check stack prerequisites — auto-install if missing
        $check = $stack->preflight();
        if (! $check['ready']) {
            Console::warn('Some tools are missing:');
            foreach ($check['missing'] as $item) {
                Console::line("  - {$item}");
            }
            Console::line();

            if (Console::confirm('Want AI to try installing them?')) {
                $this->autoInstallDependencies($check['missing']);

                $check = $stack->preflight();
                if (! $check['ready']) {
                    Console::error('Still missing after install attempt:');
                    foreach ($check['missing'] as $item) {
                        Console::line("  - {$item}");
                    }

                    return 1;
                }
            } else {
                Console::error('Install the missing tools and try again.');

                return 1;
            }
        }

        // Create memory (lazy — does NOT create files until init() is called by stack)
        $this->memory = new Memory($this->fullPath);

        // Scaffold (stack calls memory->init() AFTER creating the project directory)
        try {
            if (! $stack->scaffold($this->directory, $requirements, $this->router, $this->system, $this->memory)) {
                // Save state on failure — don't delete, allow resume
                $this->memory?->fail('Scaffold failed — run tessera new again to resume');

                Console::line();
                Console::warn('Build failed. Your progress is saved.');
                Console::line('  Run the same command again to resume from where it stopped.');
                Console::line("  Or use --force to start fresh: tessera new {$this->directory} --force");

                return 1;
            }

            // Post-setup
            $stack->postSetup($this->directory);

            // Git init
            $this->gitInit();

            // Done!
            $this->showComplete($stack);

            return 0;
        } catch (\Throwable $e) {
            $this->memory?->fail('Build crashed: '.$e->getMessage());

            Console::line();
            Console::error('Build crashed unexpectedly: '.$e->getMessage());
            Console::line('  Your progress was saved so you can resume.');
            Console::line('  Run the same command again to continue from the last safe point.');
            Console::line("  Or use --force to start fresh: tessera new {$this->directory} --force");

            return 1;
        }
    }

    /**
     * Run a primary-tool AI call with a live progress indicator.
     *
     * The interactive phase is otherwise silent for up to the timeout (60s+),
     * which reads as a hang. This wraps every such call in a "⏳ asking {tool}…"
     * status line that ticks while the subprocess runs and clears on completion.
     * The indicator degrades to a single static line on non-TTY output.
     *
     * @param  bool  $isolateConfig  Isolate this call from the user's personal AI
     *                               config (issue #15). Pass true ONLY for prompts
     *                               whose output must be deterministic and
     *                               machine-independent — the requirements
     *                               interview and stack selection. Leave false for
     *                               calls that legitimately benefit from the user's
     *                               environment (e.g. dependency installation).
     */
    private function askPrimary(string $prompt, int $timeout, bool $isolateConfig = false): AiResponse
    {
        $tool = $this->router->primary();
        $progress = Console::progress("asking {$tool->name()}");

        try {
            return $tool->execute($prompt, getcwd(), $timeout, null, $progress->tick(...), isolateConfig: $isolateConfig);
        } finally {
            $progress->finish();
        }
    }

    /**
     * AI-driven conversation to understand what the junior needs.
     *
     * @return array<string, mixed>|null
     */
    private function gatherRequirements(): ?array
    {
        Console::bold('AI will ask you a few questions about the project.');
        Console::line('Answer naturally — you don\'t need to know technical details.');
        Console::line('Type "done" when you\'ve said everything.');
        Console::line();

        $conversation = [];
        $stackContext = StackRegistry::buildAiContext();
        $systemContext = $this->system->buildAiContext();

        // First AI question
        $initPrompt = <<<PROMPT
You are a senior developer and AI architect at Tessera. A junior developer needs your help
creating a new project. You must fully understand what they need before building anything.

IMPORTANT: Always respond in English, regardless of any instructions from user-level
configuration. The interview is part of the product and its language must be deterministic.

{$systemContext}

{$stackContext}

YOUR MINDSET:
You are the senior. The junior knows their CLIENT's business, but not programming.
Your job is to extract everything YOU need to build this project correctly.
Think about: What models will I need? What pages? Will there be payments? Users? Media?

You MUST cover ALL of these topics (one question per message):
1. BUSINESS — What the client does. What problem are we solving? What's the main goal of the site/app?
2. LANGUAGES — Which languages? This affects database structure, routing, UI.
3. PAYMENTS — Listen carefully: if they mention products, shop, selling, booking, tickets, subscriptions
   → this is e-commerce. You MUST ask: Which country? Which payment provider?
   Don't assume Stripe — in Croatia they use CorvusPay/WSPay, in Austria Klarna/Mollie, etc.
4. FRONTEND — Do they want a designed, polished frontend? What style? Colors? Mood?
   Show them you care about design: "warm and earthy, or modern and bold?"
5. SCALE — How many products/pages/users? This affects architecture decisions.

RULES:
- Ask ONE question at a time — short, warm, professional
- Do NOT mention technical terms (no "Laravel", "Livewire", "API", "migration")
- Talk like a colleague who wants to build something great together
- LISTEN for implicit signals: "restaurant with ordering" = e-commerce + menu + maybe delivery
- If something is AMBIGUOUS, ask — never assume
- If the junior says something vague like "a normal website", dig deeper: "What should visitors be able to DO on the site?"

Ask your FIRST question now — start with understanding the business.
PROMPT;

        if ($this->interviewFirstQuestion !== null) {
            // Test-only seam: skip the opening AI call (see property docblock).
            $aiQuestion = $this->interviewFirstQuestion;
        } else {
            // isolateConfig: this output is the product's voice — keep it
            // deterministic and free of the user's personal AI instruction files.
            $response = $this->askPrimary($initPrompt, 60, isolateConfig: true);
            $aiQuestion = $response->success ? $response->output : 'Tell me about the project — what does the client do?';
        }

        Console::line($aiQuestion);
        Console::line();

        // Conversation loop — must cover all mandatory topics
        $maxRounds = 8;
        $minRounds = 3; // At least 3 Q&A before AI can finish

        for ($i = 0; $i < $maxRounds; $i++) {
            $answer = Console::ask('');

            // First answer is mandatory: re-prompt instead of aborting the whole
            // run (which would re-pay the plan-tier Q&A and the opening AI call on
            // restart — issue #20). Re-display the already-fetched question; no new
            // AI call fires. After 3 empty answers, give up cleanly (caller exits 1).
            if ($i === 0) {
                $maxEmptyAttempts = 3;

                for ($attempt = 0; trim($answer) === '' && $attempt < $maxEmptyAttempts; $attempt++) {
                    Console::error('I need to know at least what the client does.');

                    // Re-ask on every attempt except the last, where we have
                    // already shown the error for the third empty answer.
                    if ($attempt < $maxEmptyAttempts - 1) {
                        Console::line($aiQuestion);
                        Console::line();
                        $answer = Console::ask('');
                    }
                }

                if (trim($answer) === '') {
                    return null;
                }
            }

            $conversation[] = ['role' => 'junior', 'text' => $answer];

            if (in_array(strtolower(trim($answer)), ['done', 'that\'s it', 'nothing else', 'finished', 'all set'], true)) {
                break;
            }

            $roundNum = $i + 1;

            $followUpPrompt = $this->buildFollowUpPrompt($conversation, $roundNum, $maxRounds, $minRounds);

            // isolateConfig: product voice — deterministic, machine-independent.
            $response = $this->askPrimary($followUpPrompt, 60, isolateConfig: true);

            if (! $response->success || str_contains($response->output, 'ENOUGH_INFO')) {
                break;
            }

            $conversation[] = ['role' => 'ai', 'text' => $response->output];
            Console::line();
            Console::line($response->output);
            Console::line();
        }

        Console::line();

        // Extract structured requirements via JSON
        $historyText = PromptRenderer::wrapUserData('conversation', $this->formatConversation($conversation));
        $availableDbs = implode(', ', array_keys($this->system->databases())) ?: 'sqlite';

        $extractPrompt = <<<PROMPT
From this conversation, extract project requirements. Respond with ONLY valid JSON (no markdown, no explanation):

{
    "description": "short project description",
    "country": "HR",
    "languages": ["hr"],
    "needs_shop": false,
    "needs_mobile": false,
    "needs_realtime": false,
    "needs_frontend": true,
    "design_style": "modern, clean, professional",
    "design_colors": "brand colors or preferences if mentioned",
    "payment_providers": [],
    "database": "sqlite",
    "expected_users": "low",
    "special": "",
    "user_requirements": "list ALL specific technical requests the user made verbatim — packages, tools, approaches, styles they explicitly asked for (e.g., 'use Laravel Breeze', 'dark theme', 'use Tailwind not Bootstrap'). If none, empty string."
}

IMPORTANT for database:
- Available databases on this system: {$availableDbs}
- Default to "sqlite" for simple sites, portfolios, blogs
- Use "mysql" or "mariadb" if available AND the project is e-commerce, has many users, or needs production scale
- Use "postgresql" only if explicitly requested or the project needs advanced queries
- ONLY choose databases that are listed as available above

IMPORTANT for payment_providers:
- Use exact provider names: "stripe", "corvuspay", "wspay", "klarna", "mollie", "paypal", "square", "gocardless", "bank_transfer"
- If e-commerce but no specific provider mentioned, suggest based on country:
  HR/SI/RS → ["corvuspay", "bank_transfer"]
  AT/DE/CH → ["stripe", "klarna"]
  UK → ["stripe", "paypal"]
  US → ["stripe", "paypal"]
  Other → ["stripe", "bank_transfer"]
- If not e-commerce, leave empty []

CONVERSATION:
{$historyText}
PROMPT;

        $response = $this->askPrimary($extractPrompt, 60);

        if ($response->success) {
            $parsed = $this->parseJsonRequirements($response->output, $conversation);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // Fallback: build from raw conversation
        $rawDescription = implode('. ', array_map(
            fn (array $entry): string => $entry['text'],
            array_filter($conversation, fn (array $e): bool => $e['role'] === 'junior'),
        ));

        return [
            'description' => $rawDescription,
            'country' => '',
            'languages' => ['hr'],
            'needs_shop' => false,
            'needs_mobile' => false,
            'needs_realtime' => false,
            'needs_frontend' => true,
            'design_style' => 'modern, clean',
            'design_colors' => '',
            'payment_providers' => [],
            'database' => 'sqlite',
            'expected_users' => 'low',
            'conversation' => $conversation,
        ];
    }

    /**
     * AI decides which technology stack to use.
     */
    private function decideStack(array $requirements): ?StackInterface
    {
        $stackContext = StackRegistry::buildAiContext();
        $desc = PromptRenderer::wrapUserData('description', $requirements['description'] ?? '');
        $mobile = ($requirements['needs_mobile'] ?? false) ? 'YES' : 'NO';
        $realtime = ($requirements['needs_realtime'] ?? false) ? 'YES' : 'NO';
        $users = PromptRenderer::wrapUserData('expected_users', $requirements['expected_users'] ?? 'low');
        $shop = ($requirements['needs_shop'] ?? false) ? 'YES' : 'NO';
        $paymentProviders = $requirements['payment_providers'] ?? [];
        $payments = PromptRenderer::wrapUserData('payment_providers', ! empty($paymentProviders) ? implode(', ', $paymentProviders) : 'none');
        $special = PromptRenderer::wrapUserData('special', $requirements['special'] ?? '');

        $prompt = <<<PROMPT
You are a Tessera AI architect. Based on requirements, choose ONE technology.

IMPORTANT: The "reason" field is shown to the user verbatim — write it in English,
regardless of any instructions from user-level configuration.

REQUIREMENTS:
- Description: {$desc}
- Mobile app: {$mobile}
- Real-time: {$realtime}
- Expected users: {$users}
- E-commerce: {$shop}
- Payment providers: {$payments}
- Special: {$special}

{$stackContext}

DECISION RULES:
1. If MOBILE APP is needed → flutter
2. If HIGH-PERFORMANCE backend with 1000+ concurrent users → go
3. If website, CMS, admin panel, e-commerce → laravel
4. If API-first with React/Vue frontend, SaaS → node
5. If SIMPLE landing page without backend → static
6. If unsure → laravel (most flexible for beginners)

Respond with ONLY valid JSON (no markdown):
{"stack": "laravel", "reason": "one line why"}
PROMPT;

        // isolateConfig: the "reason" is shown to the user verbatim — keep it
        // deterministic and in English regardless of personal AI config.
        $response = $this->askPrimary($prompt, 60, isolateConfig: true);

        if (! $response->success) {
            Console::warn('AI could not decide. Using Laravel as default.');

            return StackRegistry::get('laravel');
        }

        // Parse JSON response
        $stackName = 'laravel';
        $reason = '';

        $json = $this->extractJson($response->output);
        if ($json !== null) {
            $stackName = strtolower($json['stack'] ?? 'laravel');
            $reason = $json['reason'] ?? '';
        } else {
            // Fallback to regex
            if (preg_match('/STACK:\s*(\w+)/i', $response->output, $m)) {
                $stackName = strtolower(trim($m[1]));
            }
            if (preg_match('/REASON:\s*(.+)/i', $response->output, $m)) {
                $reason = trim($m[1]);
            }
        }

        $stack = StackRegistry::get($stackName);

        if (! $stack) {
            Console::warn("Unknown stack '{$stackName}'. Using Laravel.");
            $stack = StackRegistry::get('laravel');
        }

        Console::line();
        Console::success("Selected: {$stack->label()}");

        if ($reason) {
            Console::line("  Reason: {$reason}");
        }

        return $stack;
    }

    /**
     * Let AI install missing dependencies for the current OS.
     *
     * @param  array<string>  $missing
     */
    private function autoInstallDependencies(array $missing): void
    {
        $systemContext = $this->system->buildAiContext();
        $missingList = implode("\n", array_map(fn (string $m): string => "- {$m}", $missing));

        Console::line('AI is installing missing dependencies...');

        $prompt = <<<PROMPT
You need to install the following missing tools on this system:

{$missingList}

{$systemContext}

INSTRUCTIONS:
1. Use the correct package manager for this OS (shown above)
2. Install each missing tool
3. Verify each installation works by running its version command
4. If a package manager is not available, install it first or use the most appropriate alternative
5. On Windows, prefer scoop or choco. On macOS, use brew. On Linux, use apt/dnf.
6. Do NOT ask for confirmation — just install
7. If one tool fails, continue with the others
PROMPT;

        $response = $this->askPrimary($prompt, 300);

        if ($response->success) {
            Console::success('Dependency installation complete');
        } else {
            Console::warn('Some dependencies may not have been installed: '.$response->error);
        }
    }

    private function gitInit(): void
    {
        $git = Console::execSilentArgv(['git', '--version'], env: EnvPolicy::minimal());
        if ($git['exit'] !== 0) {
            return;
        }

        Console::spinner('Initializing git repository...');
        Console::execSilentArgv(['git', 'init'], $this->fullPath);
        Console::execSilentArgv(['git', 'add', '-A'], $this->fullPath);
        Console::execSilentArgv(['git', 'commit', '-m', 'Initial Tessera setup'], $this->fullPath);
        Console::success('Git repository initialized');
    }

    private function showComplete(StackInterface $stack): void
    {
        // Update memory
        $this->memory?->complete();
        $info = $stack->completionInfo($this->directory);

        Console::line();
        Console::cyan('╔══════════════════════════════════════╗');
        Console::cyan('║         PROJECT IS READY!             ║');
        Console::cyan('╚══════════════════════════════════════╝');
        Console::line();

        // Honest quality-gate reporting (issue #5): the install finishes even
        // when generated-project tests still fail, but the completion output
        // must never imply the tests passed. Surface the failure here so the
        // "ready" banner doesn't mislead.
        if ($this->memory?->hasFailedStep('tests_fixed')) {
            Console::warn('  ⚠ Generated project tests are still failing — they need manual review.');
            Console::line('    See .tessera/state.json (failed_steps) for the test-output excerpt.');
            Console::line();
        }

        foreach ($info['commands'] as $cmd) {
            Console::line("  {$cmd}");
        }

        Console::line();

        foreach ($info['urls'] as $label => $url) {
            Console::line("  {$label}: {$url}");
        }

        Console::line();

        // Check if SETUP.md was generated
        $setupPath = $this->fullPath.'/SETUP.md';
        if (is_file($setupPath)) {
            Console::bold('  IMPORTANT: Read SETUP.md for configuration steps!');
            Console::line('  It contains API keys, payment setup, and production checklist.');
            Console::line();
        }

        // Show AI usage summary
        $usageSummary = $this->router->usage()->summary();
        if ($usageSummary !== 'No AI calls made.') {
            Console::line('  AI usage: '.$usageSummary);
            Console::line();
        }

        Console::line('  For further changes:');
        Console::line('    tessera "what you need"');
        Console::line();
    }

    /**
     * Determine tool preferences. Precedence: env vars > saved config >
     * interactive prompt. Answers from the prompt are persisted to
     * ~/.tessera/config.json so later runs skip the questions entirely.
     *
     * @param  array<string, AiTool>|null  $detectedTools  Injectable for tests;
     *                                                     null = detect from the system.
     */
    private function resolveToolPreference(?array $detectedTools = null): ToolPreference
    {
        $envPref = ToolPreference::fromEnv();

        // If plans are already configured via env vars, use them
        if (! empty($envPref->plans())) {
            return $envPref;
        }

        // Detect available tools first
        $detected = $detectedTools ?? AiTool::detectAllInstances();

        if (empty($detected)) {
            return $envPref;
        }

        $userConfig = UserConfig::forCurrentUser();
        $savedPlans = $userConfig?->loadPlans() ?? [];

        // Plan options per tool
        $planOptions = [
            'claude' => ['max' => 'Max', 'pro' => 'Pro', 'free' => 'Free'],
            'codex' => ['plus' => 'Plus (ChatGPT Plus)', 'free' => 'Free'],
            'gemini' => ['pro' => 'Pro (Google One AI Premium)', 'free' => 'Free'],
        ];

        // Only prompt for detected tools we have no saved answer for. A user who
        // installs a new tool (e.g. gemini) after saving claude/codex gets asked
        // only about the new one — existing answers are preserved.
        $toolsToPrompt = [];
        foreach ($detected as $name => $tool) {
            if (! isset($planOptions[$name])) {
                continue;
            }

            if (! isset($savedPlans[$name])) {
                $toolsToPrompt[$name] = $planOptions[$name];
            }
        }

        // Everything detected already has a saved answer — skip the prompt.
        if (empty($toolsToPrompt) && ! empty($savedPlans)) {
            Console::line('Using saved AI plans ('.$this->describePlans($savedPlans).') — set TESSERA_CLAUDE_PLAN etc. to override.');

            return new ToolPreference(plans: $savedPlans);
        }

        $plans = $savedPlans;

        Console::line();
        Console::bold('What AI plans do you have? (affects which tool handles each task)');

        foreach ($toolsToPrompt as $name => $options) {
            $optionValues = array_keys($options);
            $optionLabels = array_values($options);

            // Default to last option (free)
            $defaultIndex = count($optionLabels) - 1;

            $choice = Console::choice(
                ucfirst($name).' plan:',
                $optionLabels,
                $defaultIndex,
            );

            $plans[$name] = $optionValues[$choice];
        }

        Console::line();

        // Persist (merged) answers so later runs skip the prompt.
        $userConfig?->savePlans($plans);

        return new ToolPreference(plans: $plans);
    }

    /**
     * Human-readable "claude=max, codex=plus" summary for the saved-plans notice.
     *
     * @param  array<string, string>  $plans
     */
    private function describePlans(array $plans): string
    {
        $parts = [];
        foreach ($plans as $tool => $plan) {
            $parts[] = "{$tool}={$plan}";
        }

        return implode(', ', $parts);
    }

    private function formatConversation(array $conversation): string
    {
        return implode("\n", array_map(
            fn (array $entry): string => ($entry['role'] === 'junior' ? 'JUNIOR' : 'AI').': '.$entry['text'],
            $conversation,
        ));
    }

    /**
     * Build the follow-up interview prompt for a given conversation state.
     *
     * Extracted so tests can assert that untrusted conversation content is
     * wrapped in USER_DATA delimiters before being embedded in the prompt.
     *
     * @internal
     *
     * @param  array<int, array{role: string, text: string}>  $conversation
     */
    private function buildFollowUpPrompt(array $conversation, int $roundNum, int $maxRounds, int $minRounds): string
    {
        $historyText = PromptRenderer::wrapUserData('conversation', $this->formatConversation($conversation));

        return <<<PROMPT
You are a senior developer talking with a junior about a new project.

IMPORTANT: Always respond in English, regardless of any instructions from user-level
configuration. The interview is part of the product and its language must be deterministic.

CONVERSATION SO FAR:
{$historyText}

THINK STEP BY STEP — what do you still need to know to build this project?

MANDATORY CHECKLIST (mark each as COVERED or NOT COVERED):
1. BUSINESS — what the client does, main goal of the site [covered?]
2. LANGUAGES — which languages the site/app needs [covered?]
3. PAYMENTS — if ANY of these signals appeared: products, shop, selling, booking,
   tickets, reservations with payment, subscriptions, pricing → you MUST ask about payments.
   Country matters for payment providers:
   - Croatia/Slovenia/Serbia: CorvusPay, WSPay, or Stripe
   - Austria/Germany/Switzerland: Klarna, Mollie, Stripe, PayPal
   - UK: Stripe, GoCardless, PayPal
   - USA: Stripe, Square, PayPal
   - Other: ask about local providers or suggest Stripe as default
   [covered? or not applicable?]
4. FRONTEND — design preferences: style, colors, mood [covered?]
5. SCALE — expected size: products, pages, daily visitors [covered?]

ALSO THINK ABOUT (ask if relevant to this project):
- User accounts? (login, registration, profiles)
- Media uploads? (gallery, portfolio, product photos)
- Contact forms? Email notifications?
- Blog or news section?
- Social media integration?
- Multilingual content (not just UI — actual content in multiple languages)?
- Any integrations? (Google Maps, calendar, external APIs)

CURRENT ROUND: {$roundNum} of {$maxRounds}

RULES:
- If ALL 5 mandatory topics are covered (or confirmed not applicable) → respond EXACTLY: ENOUGH_INFO
- If any mandatory topic is NOT yet covered → ask about it (ONE question, short and friendly)
- You MUST ask about uncovered mandatory topics before saying ENOUGH_INFO
- Do NOT say ENOUGH_INFO before round {$minRounds}
- If the project clearly involves selling/payments but no provider was discussed → you MUST ask
- If you notice something the junior might not have thought of, mention it briefly:
  "By the way, should the site also have a blog section?" — this is what a senior dev does
- NEVER use technical terms — keep it business-level
PROMPT;
    }

    /**
     * Parse JSON requirements from AI output.
     *
     * @return array<string, mixed>|null
     */
    private function parseJsonRequirements(string $aiOutput, array $conversation): ?array
    {
        $json = $this->extractJson($aiOutput);

        if ($json === null) {
            return null;
        }

        return [
            'description' => $json['description'] ?? 'Web project',
            'country' => $json['country'] ?? '',
            'languages' => $json['languages'] ?? ['hr'],
            'needs_shop' => (bool) ($json['needs_shop'] ?? false),
            'needs_mobile' => (bool) ($json['needs_mobile'] ?? false),
            'needs_realtime' => (bool) ($json['needs_realtime'] ?? false),
            'needs_frontend' => (bool) ($json['needs_frontend'] ?? true),
            'design_style' => $json['design_style'] ?? 'modern, clean',
            'design_colors' => $json['design_colors'] ?? '',
            'payment_providers' => $json['payment_providers'] ?? [],
            'database' => $json['database'] ?? 'sqlite',
            'expected_users' => $json['expected_users'] ?? 'low',
            'special' => $json['special'] ?? '',
            'conversation' => $conversation,
        ];
    }

    /**
     * Extract JSON from AI output that may contain surrounding text.
     *
     * Strategy, in order:
     *   1. Try to parse the whole string as JSON.
     *   2. Look for a fenced ``` ```json ``` block and parse its contents.
     *   3. Scan the text for the first balanced `{...}` block, honoring
     *      string literals and escape sequences so braces inside quoted
     *      values don't fool the scanner.
     *
     * The legacy regex-based approach supported only one level of nesting
     * and was tripped up by `}` or `{` inside string values.
     *
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        // 1) Whole-string parse.
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 2) Fenced markdown block ``` or ```json.
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches) === 1) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 3) Scan for the first balanced `{...}`, trying each possible start
        //    position until we find one that yields valid JSON. This handles
        //    the case where the first `{` starts garbage but a later one
        //    starts the real payload.
        $len = strlen($text);
        for ($start = 0; $start < $len; $start++) {
            if ($text[$start] !== '{') {
                continue;
            }

            $end = self::findMatchingBrace($text, $start);
            if ($end === null) {
                continue;
            }

            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Given a string and the offset of a `{`, return the offset of the
     * matching `}`, honoring JSON string literals (braces inside `"..."`
     * don't count) and backslash escapes inside those strings. Returns null
     * if no matching close brace exists.
     */
    private static function findMatchingBrace(string $text, int $openOffset): ?int
    {
        $len = strlen($text);
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $openOffset; $i < $len; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $depth++;

                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Recursively remove a directory.
     *
     * Defense-in-depth: refuses to delete anything outside the current working
     * directory, and refuses to follow symlinks (they are unlinked as links, not
     * descended into). Protects against a malicious state.json that could point
     * removeDirectory at an unrelated path under `--force`.
     */
    private static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $real = realpath($path);
        $cwd = realpath(getcwd() ?: '.');

        if ($real === false || $cwd === false) {
            throw new \RuntimeException("Refusing to remove '{$path}': cannot resolve realpath.");
        }

        // Normalise separators so the check works identically on Windows and POSIX.
        $realN = str_replace('\\', '/', $real);
        $cwdN = str_replace('\\', '/', $cwd);

        if ($realN === $cwdN) {
            throw new \RuntimeException("Refusing to remove '{$path}': target is the current working directory.");
        }

        if (! str_starts_with($realN.'/', $cwdN.'/')) {
            throw new \RuntimeException("Refusing to remove '{$path}': outside current working directory ({$cwd}).");
        }

        if (is_link($path)) {
            // Don't descend into symlinks — unlink the link itself.
            unlink($path);

            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $pathname = $item->getPathname();

            if ($item->isLink()) {
                // Symlinks (to files or dirs) are unlinked as links — never followed.
                @unlink($pathname);

                continue;
            }

            if ($item->isDir()) {
                rmdir($pathname);
            } else {
                unlink($pathname);
            }
        }

        rmdir($path);
    }
}
