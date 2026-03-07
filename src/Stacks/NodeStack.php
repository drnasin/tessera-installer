<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;

/**
 * Node.js / Next.js / Express stack.
 * STATUS: Scaffold-ready. AI generates project, manual steps needed.
 */
final class NodeStack implements StackInterface
{
    public function name(): string
    {
        return 'node';
    }

    public function label(): string
    {
        return 'Node.js (Next.js / Express)';
    }

    public function description(): string
    {
        return 'API serveri, real-time aplikacije, SSR web aplikacije, '
            . 'SaaS platforme, dashboard s React/Vue frontendom. '
            . 'Najbolji izbor za: real-time chat, streaming, API-first arhitekture, '
            . 'JavaScript/TypeScript full-stack. '
            . 'Stack: Node.js 20+, TypeScript, Next.js ili Express, Prisma, PostgreSQL.';
    }

    public function preflight(): array
    {
        $missing = [];

        $node = Console::execSilent('node --version');
        if ($node['exit'] !== 0) {
            $missing[] = 'Node.js 20+ (https://nodejs.org)';
        } else {
            $version = trim(str_replace('v', '', $node['output']));
            if (version_compare($version, '20.0.0', '<')) {
                $missing[] = 'Node.js 20+ (imas: ' . $version . ')';
            }
        }

        $npm = Console::execSilent('npm --version');
        if ($npm['exit'] !== 0) {
            $missing[] = 'npm';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, AiTool $ai): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $desc = $requirements['description'] ?? 'Node.js projekt';

        Console::spinner('AI generira Node.js projekt...');

        $prompt = <<<PROMPT
Kreiraj kompletni Node.js projekt u trenutnom direktoriju.

OPIS: {$desc}

Koristi: TypeScript, Next.js (ili Express ako je API-only), Prisma ORM, PostgreSQL.
Kreiraj: package.json, tsconfig.json, osnovnu strukturu, README.md s uputama.
Postavi ESLint + Prettier. Dodaj Docker compose za dev environment.
PROMPT;

        mkdir($fullPath, 0755, true);

        $response = $ai->execute($prompt, $fullPath, 600);

        if (! $response->success) {
            Console::error('AI scaffold nije uspio: ' . $response->error);

            return false;
        }

        Console::line($response->output);
        Console::success('Node.js projekt generiran');

        return true;
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;

        Console::spinner('Instaliram npm pakete...');
        Console::exec('npm install', $fullPath);

        return true;
    }

    public function completionInfo(string $directory): array
    {
        return [
            'commands' => [
                "cd {$directory}",
                'npm run dev',
            ],
            'urls' => [
                'App' => 'http://localhost:3000',
            ],
        ];
    }
}
