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

        // Step 2: AI-driven conversation to understand requirements
        $requirements = $this->gatherRequirements();

        if ($requirements === null) {
            return 1;
        }

        // Step 3: AI decides technology stack
        $stack = $this->decideStack($requirements);

        if ($stack === null) {
            return 1;
        }

        // Step 4: Confirm with junior
        Console::line();
        Console::bold("AI preporucuje: {$stack->label()}");
        Console::line();

        if (! Console::confirm('Nastavljamo?')) {
            Console::warn('Prekinuto.');

            return 0;
        }

        // Step 5: Check stack prerequisites
        $check = $stack->preflight();
        if (! $check['ready']) {
            Console::error('Stack nije spreman. Nedostaje:');
            foreach ($check['missing'] as $item) {
                Console::line("  - {$item}");
            }

            return 1;
        }

        // Step 6: Check directory
        if (is_dir($this->fullPath)) {
            Console::error("Direktorij '{$this->directory}' vec postoji.");

            return 1;
        }

        // Step 7: Scaffold
        if (! $stack->scaffold($this->directory, $requirements, $this->ai)) {
            return 1;
        }

        // Step 8: Post-setup
        $stack->postSetup($this->directory);

        // Step 9: Done!
        $this->showComplete($stack);

        return 0;
    }

    private function showBanner(): void
    {
        Console::line();
        Console::cyan('╔══════════════════════════════════════╗');
        Console::cyan('║        TESSERA — AI Architect         ║');
        Console::cyan('║    Opisi sto trebas, AI ce odluciti   ║');
        Console::cyan('╚══════════════════════════════════════╝');
        Console::line();
    }

    private function preflight(): bool
    {
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

        // Show available stacks
        $available = StackRegistry::available();
        $all = StackRegistry::all();

        Console::line();
        Console::bold('Dostupni stackovi:');

        foreach ($all as $name => $stack) {
            $check = $stack->preflight();
            if ($check['ready']) {
                Console::success($stack->label());
            } else {
                Console::line("  \033[90m{$stack->label()} — nedostaje: " . implode(', ', $check['missing']) . "\033[0m");
            }
        }

        Console::line();

        if (empty($available)) {
            Console::error('Nijedan stack nije spreman. Instaliraj potrebne alate.');

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
        Console::bold('AI ce te pitati par pitanja o projektu.');
        Console::line('Odgovaraj prirodno — ne moras znati tehnicke detalje.');
        Console::line('Napisi "gotovo" kad si sve rekao.');
        Console::line();

        $conversation = [];
        $stackContext = StackRegistry::buildAiContext();

        // First AI question
        $initPrompt = <<<PROMPT
Ti si Tessera AI arhitekt. Pomazes junioru napraviti novi projekt.
AI alat: {$this->ai->name()}

{$stackContext}

Tvoj posao je KRATKIM pitanjima saznati:
1. Sto klijent radi i kakav projekt treba
2. Posebni zahtjevi (jezici, e-commerce, mobilna app, API...)
3. Koliko korisnika ocekuje (malo, srednje, puno)

Postavi PRVO pitanje — kratko, prijateljski, na hrvatskom. Samo jedno pitanje.
Ne spominji tehnicke detalje — junior ne mora znati sto je Laravel ili Go.
PROMPT;

        $response = $this->ai->execute($initPrompt, getcwd(), 60);
        $aiQuestion = $response->success ? $response->output : 'Opisi mi kakav projekt pravis — sto klijent radi?';

        Console::line($aiQuestion);
        Console::line();

        // Conversation loop
        $maxRounds = 5;

        for ($i = 0; $i < $maxRounds; $i++) {
            $answer = Console::ask('');

            if (trim($answer) === '' && $i === 0) {
                Console::error('Moram znati barem sto klijent radi.');

                return null;
            }

            $conversation[] = ['role' => 'junior', 'text' => $answer];

            if (in_array(strtolower(trim($answer)), ['gotovo', 'to je to', 'done', 'nista vise', 'kraj'], true)) {
                break;
            }

            $historyText = $this->formatConversation($conversation);

            $followUpPrompt = <<<PROMPT
Ti si Tessera AI arhitekt. Razgovaras s juniorom o novom projektu.

DOSADAŠNJI RAZGOVOR:
{$historyText}

Ako imas DOVOLJNO informacija za odabir tehnologije i planiranje, odgovori TOCNO: IMAM_DOVOLJNO
Ako trebas jos nesto, postavi JEDNO kratko pitanje na hrvatskom.
Budi efikasan — ne pitaj vise od potrebnog.
PROMPT;

            $response = $this->ai->execute($followUpPrompt, getcwd(), 60);

            if (! $response->success || str_contains($response->output, 'IMAM_DOVOLJNO')) {
                break;
            }

            $conversation[] = ['role' => 'ai', 'text' => $response->output];
            Console::line();
            Console::line($response->output);
            Console::line();
        }

        Console::line();

        // Extract structured requirements
        $historyText = $this->formatConversation($conversation);

        $extractPrompt = <<<PROMPT
Iz ovog razgovora izvuci podatke. Odgovori TOCNO u ovom formatu (bez markdown):

DESCRIPTION: [kratki opis projekta]
LANGUAGES: [jezici odvojeni zarezom, default: hr]
NEEDS_SHOP: [da/ne]
SHOP_DETAILS: [detalji ili prazno]
NEEDS_MOBILE: [da/ne]
NEEDS_REALTIME: [da/ne]
EXPECTED_USERS: [malo/srednje/puno]
SPECIAL: [posebni zahtjevi ili prazno]

RAZGOVOR:
{$historyText}
PROMPT;

        $response = $this->ai->execute($extractPrompt, getcwd(), 60);

        if ($response->success) {
            return $this->parseRequirements($response->output, $conversation);
        }

        // Fallback
        $rawDescription = implode('. ', array_map(
            fn ($entry) => $entry['text'],
            array_filter($conversation, fn ($e) => $e['role'] === 'junior'),
        ));

        return [
            'description' => $rawDescription,
            'languages' => ['hr'],
            'needs_shop' => false,
            'needs_mobile' => false,
            'needs_realtime' => false,
            'expected_users' => 'malo',
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
        $mobile = ($requirements['needs_mobile'] ?? false) ? 'DA' : 'NE';
        $realtime = ($requirements['needs_realtime'] ?? false) ? 'DA' : 'NE';
        $users = $requirements['expected_users'] ?? 'malo';
        $shop = ($requirements['needs_shop'] ?? false) ? 'DA' : 'NE';
        $special = $requirements['special'] ?? '';

        Console::spinner('AI bira tehnologiju...');

        $prompt = <<<PROMPT
Ti si Tessera AI arhitekt. Na temelju zahtjeva, odaberi JEDNU tehnologiju.

ZAHTJEVI:
- Opis: {$desc}
- Mobilna app: {$mobile}
- Real-time: {$realtime}
- Ocekivani korisnici: {$users}
- E-commerce: {$shop}
- Posebno: {$special}

{$stackContext}

PRAVILA ODLUCIVANJA:
1. Ako treba MOBILNA APP → flutter
2. Ako treba HIGH-PERFORMANCE backend s 1000+ istovremenih korisnika → go
3. Ako treba web stranica, CMS, admin panel, e-commerce → laravel
4. Ako treba API-first s React/Vue frontendom, SaaS → node
5. Ako treba JEDNOSTAVNA landing stranica bez backend-a → static
6. Ako nisi siguran → laravel (najfleksibilniji za pocetnike)

Odgovori TOCNO u ovom formatu (bez markdown, bez objasnjenja):
STACK: [ime stacka]
REASON: [jedan red zasto]
PROMPT;

        $response = $this->ai->execute($prompt, getcwd(), 60);

        if (! $response->success) {
            Console::warn('AI nije mogao odluciti. Koristim Laravel kao default.');

            return StackRegistry::get('laravel');
        }

        // Parse AI response
        $stackName = 'laravel';

        if (preg_match('/STACK:\s*(\w+)/i', $response->output, $m)) {
            $stackName = strtolower(trim($m[1]));
        }

        $reason = '';
        if (preg_match('/REASON:\s*(.+)/i', $response->output, $m)) {
            $reason = trim($m[1]);
        }

        $stack = StackRegistry::get($stackName);

        if (! $stack) {
            Console::warn("Nepoznat stack '{$stackName}'. Koristim Laravel.");
            $stack = StackRegistry::get('laravel');
        }

        Console::line();
        Console::success("Odabrano: {$stack->label()}");

        if ($reason) {
            Console::line("  Razlog: {$reason}");
        }

        return $stack;
    }

    private function showComplete(StackInterface $stack): void
    {
        $info = $stack->completionInfo($this->directory);

        Console::line();
        Console::cyan('╔══════════════════════════════════════╗');
        Console::cyan('║         PROJEKT JE SPREMAN!          ║');
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
        Console::line('  Za dalje promjene:');
        Console::line('    tessera "sto trebas"');
        Console::line();
    }

    private function formatConversation(array $conversation): string
    {
        return implode("\n", array_map(
            fn ($entry) => ($entry['role'] === 'junior' ? 'JUNIOR' : 'AI') . ': ' . $entry['text'],
            $conversation,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRequirements(string $aiOutput, array $conversation): array
    {
        $get = function (string $key) use ($aiOutput): string {
            if (preg_match('/' . $key . ':\s*(.+)/i', $aiOutput, $m)) {
                return trim($m[1]);
            }

            return '';
        };

        $languages = array_map('trim', explode(',', $get('LANGUAGES') ?: 'hr'));
        $needsShop = in_array(strtolower($get('NEEDS_SHOP')), ['da', 'yes', 'true'], true);
        $needsMobile = in_array(strtolower($get('NEEDS_MOBILE')), ['da', 'yes', 'true'], true);
        $needsRealtime = in_array(strtolower($get('NEEDS_REALTIME')), ['da', 'yes', 'true'], true);

        return [
            'description' => $get('DESCRIPTION') ?: 'Web projekt',
            'languages' => $languages,
            'needs_shop' => $needsShop,
            'needs_mobile' => $needsMobile,
            'needs_realtime' => $needsRealtime,
            'expected_users' => $get('EXPECTED_USERS') ?: 'malo',
            'special' => $get('SPECIAL') ?: '',
            'conversation' => $conversation,
        ];
    }
}
