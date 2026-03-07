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

        Console::spinner('AI is generating Node.js project...');

        if (! @mkdir($fullPath, 0755, true) && ! is_dir($fullPath)) {
            Console::error("Could not create directory: {$fullPath}");

            return false;
        }

        $prompt = <<<PROMPT
Create a complete Node.js project in the current directory.

DESCRIPTION: {$desc}

Use: TypeScript, Next.js (or Express if API-only), Prisma ORM, PostgreSQL.
Create: package.json, tsconfig.json, basic structure, README.md with instructions.
Set up ESLint + Prettier. Add Docker compose for dev environment.
PROMPT;

        $response = $ai->execute($prompt, $fullPath, 600);

        if (! $response->success) {
            Console::error('AI scaffold failed: ' . $response->error);

            return false;
        }

        Console::line($response->output);
        Console::success('Node.js project generated');

        return true;
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;

        Console::spinner('Installing npm packages...');
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
