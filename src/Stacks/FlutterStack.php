<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\AiTool;
use Tessera\Installer\Console;
use Tessera\Installer\Memory;
use Tessera\Installer\StepRunner;
use Tessera\Installer\SystemInfo;

/**
 * Flutter / Dart stack for mobile and web apps.
 */
final class FlutterStack implements StackInterface
{
    private StepRunner $steps;

    private string $fullPath;

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
        return 'Cross-platform mobile apps (iOS + Android), web apps, '
            . 'desktop apps — all from a single codebase. '
            . 'Best for: client-facing mobile apps, delivery apps, '
            . 'POS systems, fitness apps, social media. '
            . 'Stack: Dart (latest), Flutter (latest), Riverpod/Bloc, Dio, Firebase/Supabase.';
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
            $missing[] = 'Dart SDK (comes with Flutter)';
        }

        return ['ready' => empty($missing), 'missing' => $missing];
    }

    public function scaffold(string $directory, array $requirements, AiTool $ai, SystemInfo $system, Memory $memory): bool
    {
        $this->fullPath = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $this->steps = new StepRunner($ai, $this->fullPath);

        $memory->init($directory, 'flutter', $requirements, $system->buildAiContext());

        $desc = $requirements['description'] ?? 'Flutter app';
        $designStyle = $requirements['design_style'] ?? 'modern, clean';
        $designColors = $requirements['design_colors'] ?? 'use Material 3 default palette';
        $langs = implode(', ', $requirements['languages'] ?? ['en']);
        $versions = $this->detectVersions();
        $systemContext = $system->buildAiContext();

        Console::line();
        Console::bold('Building your app — this takes about 5-10 minutes.');
        Console::line();

        // Step 1: Create Flutter project
        $parentRunner = new StepRunner($ai, getcwd());
        $result = $parentRunner->runCommand(
            name: '[1/4] Create Flutter project',
            command: "flutter create {$directory} --org com.tessera --no-pub",
            verify: fn (): ?string => is_file($this->fullPath . '/pubspec.yaml') ? null : 'pubspec.yaml not found',
            fixHint: "Run: flutter create {$directory} --org com.tessera",
        );

        if (! $result) {
            return false;
        }

        // Step 2: AI configure
        $this->steps->runAi(
            name: '[2/4] Configuring app structure and screens',
            prompt: <<<PROMPT
A Flutter project was just created. Configure it for production.

{$systemContext}

RUNTIME: {$versions}
DESCRIPTION: {$desc}
LANGUAGES: {$langs}
DESIGN STYLE: {$designStyle}
DESIGN COLORS: {$designColors}

Do:
1. Add to pubspec.yaml: riverpod, dio, go_router, freezed, json_annotation
2. Create structure: lib/features/, lib/core/, lib/shared/
3. Set up routing (go_router), state management (Riverpod), API layer (Dio)
4. Create screens based on description with realistic UI
5. Material 3 theme with colors matching: {$designColors}
6. Support for languages: {$langs}
7. README.md with instructions

IMPORTANT: Use features appropriate for the detected Dart/Flutter version.
PROMPT,
            verify: null,
            skippable: true,
            timeout: 600,
        );

        // Step 3: Generate tests
        $this->steps->runAi(
            name: '[3/4] Generating tests',
            prompt: <<<PROMPT
Create Flutter/Dart tests for this project in test/ directory:
1. Widget tests for key screens
2. Unit tests for services/providers
3. Use flutter_test package

IMPORTANT: Write ONLY tests that will PASS with the current codebase.
PROMPT,
            verify: null,
            skippable: true,
            timeout: 300,
        );

        // Step 4: Run tests
        $this->steps->runAi(
            name: '[4/4] Running and fixing tests',
            prompt: <<<PROMPT
Run the project tests with: flutter test
If any tests fail, analyze the output and fix either the test or the code.
Do NOT delete tests — fix them.
PROMPT,
            verify: null,
            skippable: true,
            timeout: 300,
        );

        $this->steps->printSummary();

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

    private function detectVersions(): string
    {
        $versions = [];

        $flutter = Console::execSilent('flutter --version');
        if ($flutter['exit'] === 0) {
            // First line is like "Flutter 3.24.0 • channel stable..."
            $firstLine = strtok($flutter['output'], "\n");
            $versions[] = trim((string) $firstLine);
        }

        $dart = Console::execSilent('dart --version');
        if ($dart['exit'] === 0) {
            $versions[] = trim($dart['output']);
        }

        return empty($versions) ? 'Flutter (latest), Dart (latest)' : implode(', ', $versions);
    }
}
