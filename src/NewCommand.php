<?php

declare(strict_types=1);

namespace Tessera\Installer;

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

    private ToolRouter $router;

    private SystemInfo $system;

    private ?Memory $memory = null;

    private bool $force;

    public function __construct(string $directory, bool $force = false)
    {
        $this->directory = $directory;
        $this->fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $this->force = $force;
        $this->system = SystemInfo::detect();
    }

    public function run(): int
    {
        $this->showBanner();

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

        // Step 3: AI-driven conversation to understand requirements
        $requirements = $this->gatherRequirements();

        if ($requirements === null) {
            return 1;
        }

        return $this->buildProject($requirements);
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

    private function preflight(): bool
    {
        // Check AI tools
        $this->router = ToolRouter::detect();

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
                Console::line("  \033[90m{$stack->label()} — missing: " . implode(', ', $check['missing']) . "\033[0m");
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
        $stateFile = $this->fullPath . DIRECTORY_SEPARATOR . '.tessera' . DIRECTORY_SEPARATOR . 'state.json';

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
                    // Resume with saved requirements
                    Console::success('Resuming previous installation...');

                    return $this->buildProject($state['requirements']);
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
     * @param array<string, mixed> $requirements
     */
    private function buildProject(array $requirements): int
    {
        // Decide technology stack
        $stack = $this->decideStack($requirements);

        if ($stack === null) {
            return 1;
        }

        // Confirm with junior
        Console::line();
        Console::bold("AI recommends: {$stack->label()}");
        Console::line();

        if (! Console::confirm('Continue?')) {
            Console::warn('Cancelled.');

            return 0;
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

        $response = $this->router->primary()->execute($initPrompt, getcwd(), 60);
        $aiQuestion = $response->success ? $response->output : 'Tell me about the project — what does the client do?';

        Console::line($aiQuestion);
        Console::line();

        // Conversation loop — must cover all mandatory topics
        $maxRounds = 8;
        $minRounds = 3; // At least 3 Q&A before AI can finish

        for ($i = 0; $i < $maxRounds; $i++) {
            $answer = Console::ask('');

            if (trim($answer) === '' && $i === 0) {
                Console::error('I need to know at least what the client does.');

                return null;
            }

            $conversation[] = ['role' => 'junior', 'text' => $answer];

            if (in_array(strtolower(trim($answer)), ['done', 'that\'s it', 'nothing else', 'finished', 'all set'], true)) {
                break;
            }

            $historyText = $this->formatConversation($conversation);
            $roundNum = $i + 1;

            $followUpPrompt = <<<PROMPT
You are a senior developer talking with a junior about a new project.

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

            $response = $this->router->primary()->execute($followUpPrompt, getcwd(), 60);

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
        $historyText = $this->formatConversation($conversation);

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
    "expected_users": "low",
    "special": ""
}

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

        $response = $this->router->primary()->execute($extractPrompt, getcwd(), 60);

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
        $desc = $requirements['description'] ?? '';
        $mobile = ($requirements['needs_mobile'] ?? false) ? 'YES' : 'NO';
        $realtime = ($requirements['needs_realtime'] ?? false) ? 'YES' : 'NO';
        $users = $requirements['expected_users'] ?? 'low';
        $shop = ($requirements['needs_shop'] ?? false) ? 'YES' : 'NO';
        $paymentProviders = $requirements['payment_providers'] ?? [];
        $payments = ! empty($paymentProviders) ? implode(', ', $paymentProviders) : 'none';
        $special = $requirements['special'] ?? '';

        Console::spinner('AI is choosing technology...');

        $prompt = <<<PROMPT
You are a Tessera AI architect. Based on requirements, choose ONE technology.

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

        $response = $this->router->primary()->execute($prompt, getcwd(), 60);

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
     * @param array<string> $missing
     */
    private function autoInstallDependencies(array $missing): void
    {
        $systemContext = $this->system->buildAiContext();
        $missingList = implode("\n", array_map(fn (string $m): string => "- {$m}", $missing));

        Console::spinner('AI is installing missing dependencies...');

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

        $response = $this->router->primary()->execute($prompt, getcwd(), 300);

        if ($response->success) {
            Console::success('Dependency installation complete');
        } else {
            Console::warn('Some dependencies may not have been installed: ' . $response->error);
        }
    }

    private function gitInit(): void
    {
        $git = Console::execSilent('git --version');
        if ($git['exit'] !== 0) {
            return;
        }

        Console::spinner('Initializing git repository...');
        Console::execSilent('git init', $this->fullPath);
        Console::execSilent('git add -A', $this->fullPath);
        Console::execSilent('git commit -m "Initial Tessera setup"', $this->fullPath);
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

        foreach ($info['commands'] as $cmd) {
            Console::line("  {$cmd}");
        }

        Console::line();

        foreach ($info['urls'] as $label => $url) {
            Console::line("  {$label}: {$url}");
        }

        Console::line();

        // Check if SETUP.md was generated
        $setupPath = $this->fullPath . '/SETUP.md';
        if (is_file($setupPath)) {
            Console::bold('  IMPORTANT: Read SETUP.md for configuration steps!');
            Console::line('  It contains API keys, payment setup, and production checklist.');
            Console::line();
        }

        Console::line('  For further changes:');
        Console::line('    tessera "what you need"');
        Console::line();
    }

    private function formatConversation(array $conversation): string
    {
        return implode("\n", array_map(
            fn (array $entry): string => ($entry['role'] === 'junior' ? 'JUNIOR' : 'AI') . ': ' . $entry['text'],
            $conversation,
        ));
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
            'expected_users' => $json['expected_users'] ?? 'low',
            'special' => $json['special'] ?? '',
            'conversation' => $conversation,
        ];
    }

    /**
     * Extract JSON from AI output that may contain surrounding text.
     *
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        // Try direct parse first
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try to find JSON within the text
        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Recursively remove a directory.
     */
    private static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
