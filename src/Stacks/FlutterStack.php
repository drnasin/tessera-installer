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

        Console::spinner('Creating Flutter project...');

        $exit = Console::exec("flutter create {$directory} --org com.tessera --no-pub");

        if ($exit !== 0) {
            Console::error('flutter create failed.');

            return false;
        }

        Console::spinner('AI is configuring Flutter project...');

        $prompt = <<<PROMPT
A Flutter project was just created. Configure it for production.

DESCRIPTION: {$desc}

Do:
1. Add to pubspec.yaml: riverpod, dio, go_router, freezed, json_annotation
2. Create structure: lib/features/, lib/core/, lib/shared/
3. Set up routing (go_router), state management (Riverpod), API layer (Dio)
4. Create basic screens based on description
5. Add Material 3 theme
6. README.md with instructions
PROMPT;

        $response = $ai->execute($prompt, $fullPath, 600);

        if (! $response->success) {
            Console::warn('AI configuration partially succeeded. Continuing...');
        } else {
            Console::line($response->output);
        }

        Console::success('Flutter project generated');

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
