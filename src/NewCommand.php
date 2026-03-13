<?php

declare(strict_types=1);

namespace Tessera\Installer;

use Tessera\Installer\Stacks\StackInterface;
use Tessera\Installer\Stacks\StackRegistry;

/**
 * `tessera new {directory}` — AI-powered project scaffolding.
 *
 * Flow:
 * 1. Detect AI tool + available stacks
 * 2. AI-driven conversation with junior dev
 * 3. AI decides technology stack + architecture
 * 4. Stack driver handles everything else
 */
final class NewCommand
{
    private string $directory;

    private string $fullPath;

    private AiTool $ai;

    private bool $force;

    public function __construct(string $directory, bool $force = false)
    {
        $this->directory = $directory;
        $this->fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $this->force = $force;
    }

    public function run(): int
    {
        $this->showBanner();

        // Step 1: Preflight checks
        if (! $this->preflight()) {
            return 1;
        }

        // Step 2: Check directory
        if (is_dir($this->fullPath)) {
            if (! $this->force) {
                Console::error("Directory '{$this->directory}' already exists.");
                Console::line('  Use --force to overwrite.');

                return 1;
            }

            Console::warn("Directory '{$this->directory}' exists. Overwriting (--force)...");
            self::removeDirectory($this->fullPath);
        }

        // Step 3: AI-driven conversation to understand requirements
        $requirements = $this->gatherRequirements();

        if ($requirements === null) {
            return 1;
        }

        // Step 4: AI decides technology stack
        $stack = $this->decideStack($requirements);

        if ($stack === null) {
            return 1;
        }

        // Step 5: Confirm with junior
        Console::line();
        Console::bold("AI recommends: {$stack->label()}");
        Console::line();

        if (! Console::confirm('Continue?')) {
            Console::warn('Cancelled.');

            return 0;
        }

        // Step 6: Check stack prerequisites
        $check = $stack->preflight();
        if (! $check['ready']) {
            Console::error('Stack not ready. Missing:');
            foreach ($check['missing'] as $item) {
                Console::line("  - {$item}");
            }

            return 1;
        }

        // Step 7: Scaffold
        if (! $stack->scaffold($this->directory, $requirements, $this->ai)) {
            // Rollback: remove partial directory on failure
            if (is_dir($this->fullPath)) {
                Console::warn('Cleaning up partial project...');
                self::removeDirectory($this->fullPath);
            }

            return 1;
        }

        // Step 8: Post-setup
        $stack->postSetup($this->directory);

        // Step 9: Git init
        $this->gitInit();

        // Step 10: Done!
        $this->showComplete($stack);

        return 0;
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
        // Check AI tool
        $this->ai = AiTool::detect();

        if ($this->ai === null) {
            Console::error('No AI tool found!');
            Console::line('Install at least one:');
            Console::line('  - claude: https://docs.anthropic.com/en/docs/claude-code');
            Console::line('  - gemini: https://ai.google.dev/gemini-api/docs/cli');
            Console::line('  - codex:  https://github.com/openai/codex');

            return false;
        }

        Console::success("AI: {$this->ai->name()}");

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

        // First AI question
        $initPrompt = <<<PROMPT
You are a Tessera AI architect. You help a junior developer create a new project.
AI tool: {$this->ai->name()}

{$stackContext}

Your job is to ask SHORT questions to find out:
1. What the client does and what kind of project they need
2. Special requirements (languages, e-commerce, mobile app, API...)
3. Expected number of users (low, medium, high)
4. Whether they want a designed frontend (landing pages, styled theme) or just backend/API
5. If they want frontend — ask about design preferences (colors, style, mood)

Ask your FIRST question — short, friendly. Only one question.
Don't mention technical details — the junior doesn't need to know what Laravel or Go is.
PROMPT;

        $response = $this->ai->execute($initPrompt, getcwd(), 60);
        $aiQuestion = $response->success ? $response->output : 'Tell me about the project — what does the client do?';

        Console::line($aiQuestion);
        Console::line();

        // Conversation loop
        $maxRounds = 5;

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

            $followUpPrompt = <<<PROMPT
You are a Tessera AI architect. You're talking with a junior about a new project.

CONVERSATION SO FAR:
{$historyText}

If you have ENOUGH information to choose a technology and plan, respond EXACTLY: ENOUGH_INFO
If you need more, ask ONE short question.
Be efficient — don't ask more than necessary.
PROMPT;

            $response = $this->ai->execute($followUpPrompt, getcwd(), 60);

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
    "languages": ["hr"],
    "needs_shop": false,
    "needs_mobile": false,
    "needs_realtime": false,
    "needs_frontend": true,
    "design_style": "modern, clean, professional",
    "design_colors": "brand colors or preferences if mentioned",
    "expected_users": "low",
    "special": ""
}

CONVERSATION:
{$historyText}
PROMPT;

        $response = $this->ai->execute($extractPrompt, getcwd(), 60);

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
            'languages' => ['hr'],
            'needs_shop' => false,
            'needs_mobile' => false,
            'needs_realtime' => false,
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

        $response = $this->ai->execute($prompt, getcwd(), 60);

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
            'languages' => $json['languages'] ?? ['hr'],
            'needs_shop' => (bool) ($json['needs_shop'] ?? false),
            'needs_mobile' => (bool) ($json['needs_mobile'] ?? false),
            'needs_realtime' => (bool) ($json['needs_realtime'] ?? false),
            'needs_frontend' => (bool) ($json['needs_frontend'] ?? true),
            'design_style' => $json['design_style'] ?? 'modern, clean',
            'design_colors' => $json['design_colors'] ?? '',
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
