<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\Console;
use Tessera\Installer\Memory;
use Tessera\Installer\StepRunner;
use Tessera\Installer\SystemInfo;
use Tessera\Installer\ToolRouter;

/**
 * Flutter / Dart stack for mobile and web apps.
 *
 * Sprint 1 port: the four AI prompts (scaffold / tests / tests_fixed /
 * setup_md) live in `stacks/flutter.yaml` as versioned templates. The
 * `flutter create` shell command stays in this PHP file because it
 * needs an empty target directory and runs before the manifest. iOS
 * code signing and Android keystore work also remain in PHP — those
 * are too platform-quirky to push through YAML in v1.
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
        return 'Cross-platform mobile apps (iOS + Android), web apps, '
            .'desktop apps — all from a single codebase. '
            .'Best for: client-facing mobile apps, delivery apps, '
            .'POS systems, fitness apps, social media. '
            .'Stack: Dart (latest), Flutter (latest), Riverpod/Bloc, Dio, Firebase/Supabase.';
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

    public function scaffold(string $directory, array $requirements, ToolRouter $router, SystemInfo $system, Memory $memory): bool
    {
        $fullPath = getcwd().DIRECTORY_SEPARATOR.$directory;
        $resuming = is_file($fullPath.'/pubspec.yaml');

        // Pre-AI: `flutter create` in the parent cwd. This must run BEFORE
        // YamlStackRunner because the manifest engine creates the project
        // directory itself; flutter create insists on creating its own.
        if (! $resuming) {
            $parentRunner = new StepRunner($router, getcwd());
            $result = $parentRunner->runCommand(
                name: '[1/5] Create Flutter project',
                command: "flutter create {$directory} --org com.tessera --no-pub",
                verify: fn (): ?string => is_file($fullPath.'/pubspec.yaml') ? null : 'pubspec.yaml not found',
                fixHint: "Run: flutter create {$directory} --org com.tessera",
            );

            if (! $result) {
                return false;
            }
        } else {
            Console::success('[1/5] Create Flutter project (already done)');
        }

        return (new YamlStackRunner)->run(
            directory: $directory,
            stackName: 'flutter',
            requirements: $requirements,
            router: $router,
            system: $system,
            memory: $memory,
        );
    }

    public function postSetup(string $directory): bool
    {
        $fullPath = getcwd().DIRECTORY_SEPARATOR.$directory;

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
                'Setup guide' => 'SETUP.md',
            ],
        ];
    }
}
