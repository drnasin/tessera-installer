<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;

/**
 * Flutter / Dart stack for mobile and web apps.
 * STATUS: Scaffold-ready. AI generates project, manual steps needed.
 */
final class FlutterStack implements StackInterface
{
    public function name(): string
    {
        return 'flutter';
    }

    public function label(): string
    {
        return 'Flutter (Mobile + Web App)';
    }

    public function description(): string
    {
        return 'Cross-platform mobilne aplikacije (iOS + Android), web aplikacije, '
            . 'desktop aplikacije — sve iz jednog codebase-a. '
            . 'Najbolji izbor za: mobilne appove za klijente, delivery appove, '
            . 'POS sustave, fitness appove, social media. '
            . 'Stack: Dart 3, Flutter 3.19+, Riverpod/Bloc, Dio, Firebase/Supabase.';
    }

    public function preflight(): array
    {
        $missing = [];

        $flutter = Console::execSilent('flutter --version');
        if ($flutter['exit'] !== 0) {
            $missing[] = 'Flutter SDK (https://flutter.dev)';
        }

        $dart = Console::execSilent('dart --version');
        if ($dart['exit'] !== 0) {
            $missing[] = 'Dart SDK (dolazi s Flutter-om)';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, AiTool $ai): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $desc = $requirements['description'] ?? 'Flutter app';

        Console::spinner('Kreiram Flutter projekt...');

        $exit = Console::exec("flutter create {$directory} --org com.tessera --no-pub");

        if ($exit !== 0) {
            Console::error('flutter create nije uspio.');

            return false;
        }

        Console::spinner('AI konfigurira Flutter projekt...');

        $prompt = <<<PROMPT
Flutter projekt je upravo kreiran. Konfiguriraj ga za produkciju.

OPIS: {$desc}

Napravi:
1. Dodaj u pubspec.yaml: riverpod, dio, go_router, freezed, json_annotation
2. Kreiraj strukturu: lib/features/, lib/core/, lib/shared/
3. Postavi routing (go_router), state management (Riverpod), API layer (Dio)
4. Kreiraj osnovne ekrane prema opisu
5. Dodaj theme s Material 3
6. README.md s uputama
PROMPT;

        $response = $ai->execute($prompt, $fullPath, 600);

        if (! $response->success) {
            Console::warn('AI konfiguracija djelomicno uspjela. Nastavljam...');
        } else {
            Console::line($response->output);
        }

        Console::success('Flutter projekt generiran');

        return true;
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;

        Console::spinner('Flutter pub get...');
        Console::exec('flutter pub get', $fullPath);

        return true;
    }

    public function completionInfo(string $directory): array
    {
        return [
            'commands' => [
                "cd {$directory}",
                'flutter run',
            ],
            'urls' => [
                'Web' => 'flutter run -d chrome',
                'iOS' => 'flutter run -d ios',
                'Android' => 'flutter run -d android',
            ],
        ];
    }
}
