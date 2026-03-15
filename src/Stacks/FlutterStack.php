<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\Complexity;
use Tessera\Installer\Console;
use Tessera\Installer\Memory;
use Tessera\Installer\StepRunner;
use Tessera\Installer\SystemInfo;
use Tessera\Installer\ToolRouter;

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
        $this->fullPath = getcwd().DIRECTORY_SEPARATOR.$directory;
        $this->steps = new StepRunner($router, $this->fullPath);

        // NOTE: memory->init() is called AFTER flutter create
        // because flutter create requires an empty/non-existent directory

        $desc = $requirements['description'] ?? 'Flutter app';
        $designStyle = $requirements['design_style'] ?? 'modern, clean';
        $designColors = $requirements['design_colors'] ?? 'use Material 3 default palette';
        $langs = implode(', ', $requirements['languages'] ?? ['en']);
        $paymentProviders = $requirements['payment_providers'] ?? [];
        $payments = ! empty($paymentProviders) ? implode(', ', $paymentProviders) : 'none';
        $shop = ($requirements['needs_shop'] ?? false) ? 'YES' : 'NO';
        $country = $requirements['country'] ?? '';
        $versions = $this->detectVersions();
        $systemContext = $system->buildAiContext();

        // Check if we're resuming
        $resuming = is_file($this->fullPath.'/pubspec.yaml');

        Console::line();
        if ($resuming) {
            Console::bold('Resuming build — skipping completed steps...');
        } else {
            Console::bold('Building your app — this takes about 5-10 minutes.');
        }
        Console::line();

        // Step 1: Create Flutter project (skip if resuming)
        if (! $resuming) {
            $parentRunner = new StepRunner($router, getcwd());
            $result = $parentRunner->runCommand(
                name: '[1/5] Create Flutter project',
                command: "flutter create {$directory} --org com.tessera --no-pub",
                verify: fn (): ?string => is_file($this->fullPath.'/pubspec.yaml') ? null : 'pubspec.yaml not found',
                fixHint: "Run: flutter create {$directory} --org com.tessera",
            );

            if (! $result) {
                return false;
            }
        } else {
            Console::success('[1/5] Create Flutter project (already done)');
        }

        // Init or update memory — project directory exists
        if ($memory->hasState()) {
            $memory->updateContext($requirements, $system->buildAiContext());
        } else {
            $memory->init($directory, 'flutter', $requirements, $system->buildAiContext());
        }

        // Step 2: AI configure — senior dev reasoning
        if ($memory->isStepDone('scaffold')) {
            Console::success('[2/5] Configuring app structure and screens (already done)');
        } else {
            $memory->startStep('scaffold');
            $this->steps->runAi(
                name: '[2/5] Configuring app structure and screens',
                prompt: <<<PROMPT
You are a SENIOR Flutter/Dart developer configuring a project for production.
A Flutter project was just created with `flutter create`. Now make it production-ready.
Think carefully about what THIS specific app needs.

{$systemContext}

RUNTIME: {$versions}
PROJECT: {$desc}
LANGUAGES: {$langs}
DESIGN STYLE: {$designStyle}
DESIGN COLORS: {$designColors}
E-COMMERCE: {$shop}
PAYMENT PROVIDERS: {$payments}
COUNTRY: {$country}

STEP 1 — THINK (do not skip):
- What screens does this app need? (Home, Profile, Settings, Product List, Cart, Checkout?)
- What data models are needed? (User, Product, Order, Message?)
- Does it need authentication? (most mobile apps do)
- Does it need payments? Which SDK? (stripe_sdk, in_app_purchase?)
- Does it need push notifications?
- Does it need a backend? (Firebase, Supabase, custom API?)
- Does it need offline support? (local database, caching?)
- What state management fits? (Riverpod for most cases)

STEP 2 — CONFIGURE:
1. pubspec.yaml — add ALL needed dependencies:
   - State management: flutter_riverpod
   - Routing: go_router
   - HTTP: dio
   - Code generation: freezed, json_serializable, build_runner
   - Local storage: shared_preferences or hive
   - IF authentication: firebase_auth or supabase_flutter
   - IF e-commerce: payment SDKs for {$payments}
   - IF push notifications: firebase_messaging
   - IF offline: sqflite or isar

2. Project structure:
   lib/
     core/ — theme, constants, utils, extensions
     features/ — one folder per feature (auth/, home/, products/, cart/, etc.)
       each feature: screens/, widgets/, providers/, models/
     shared/ — shared widgets, services, models
     router.dart — go_router configuration
     app.dart — MaterialApp.router with theme

3. Theme (lib/core/theme.dart):
   - Material 3 with ColorScheme matching: {$designColors}
   - Style: {$designStyle}
   - Custom TextTheme, ButtonTheme, CardTheme
   - Light and dark mode support

4. Screens — realistic UI for THIS app:
   - Create screens based on what the app needs (think step 1)
   - Each screen must look professional — proper spacing, typography, icons
   - Use Material 3 components (FilledButton, Card, NavigationBar)
   - Content in {$langs}

5. IF E-COMMERCE ({$shop}):
   - Product listing with grid/list toggle, search, filtering
   - Product detail screen with images, variants, add-to-cart
   - Cart screen with quantity management
   - Checkout flow with payment integration ({$payments})
   - Order history screen
   - Define required API configuration in a config class

6. .env.example or lib/core/config.dart with ALL needed configuration:
   - API base URL
   - Payment provider keys
   - Firebase config (if used)
   Each config must have a comment explaining where to get the value

7. README.md with setup instructions, required accounts, architecture overview

IMPORTANT: Use features appropriate for the detected Dart/Flutter version.

THREE RULES:
1. DESIGN FOR THE USER. Ask what the app does and who uses it before choosing colors or theme.
   Light themes for most apps. Design for the user, not for developers.
2. USE THE APP IN YOUR HEAD. Tap every button, navigate every screen, go back, rotate the phone.
   Does every screen handle loading, empty, and error states? Is everything tappable (44px min)?
3. VERIFY BEFORE YOU USE. Before referencing any route, provider, model field, or package —
   read the source file where it's defined. Never assume names from memory.
PROMPT,
                verify: null,
                skippable: true,
                timeout: 600,
                complexity: Complexity::COMPLEX,
            );
            $memory->completeStep('scaffold');
        } // end if !isStepDone('scaffold')

        // Step 3: Generate tests
        if ($memory->isStepDone('tests')) {
            Console::success('[3/5] Generating tests (already done)');
        } else {
            $memory->startStep('tests');
            $this->steps->runAi(
                name: '[3/5] Generating tests',
                prompt: <<<'PROMPT'
Create Flutter/Dart tests for this project. Read the project first.

test/ directory:
1. Widget tests — test that key screens render without errors, test user interactions
2. Unit tests — test providers/services, data models, business logic
3. IF e-commerce: test cart operations, price calculations, order creation

Use flutter_test and mocktail for mocking.
IMPORTANT: Write ONLY tests that will PASS with the current codebase.
PROMPT,
                verify: null,
                skippable: true,
                timeout: 300,
                complexity: Complexity::MEDIUM,
            );
            $memory->completeStep('tests');
        } // end if !isStepDone('tests')

        // Step 4: Run tests and fix
        if ($memory->isStepDone('tests_fixed')) {
            Console::success('[4/5] Running and fixing tests (already done)');
        } else {
            $memory->startStep('tests_fixed');
            $this->steps->runAi(
                name: '[4/5] Running and fixing tests',
                prompt: <<<'PROMPT'
Run the project tests with: flutter test
If any tests fail, analyze the output and fix either the test or the code.
Do NOT delete tests — fix them.
PROMPT,
                verify: null,
                skippable: true,
                timeout: 300,
                complexity: Complexity::MEDIUM,
            );
            $memory->completeStep('tests_fixed');
        } // end if !isStepDone('tests_fixed')

        // Step 5: SETUP.md — developer handoff
        if ($memory->isStepDone('setup_md')) {
            Console::success('[5/5] Generating setup instructions (already done)');
        } else {
            $memory->startStep('setup_md');
            $this->steps->runAi(
                name: '[5/5] Generating setup instructions',
                prompt: <<<PROMPT
Read the entire project you just built. Generate a SETUP.md file in the project root.

PROJECT: {$desc}
E-COMMERCE: {$shop}
PAYMENT PROVIDERS: {$payments}
COUNTRY: {$country}

SETUP.md must include:

1. QUICK START — flutter pub get, flutter run, available platforms
2. REQUIRED ACCOUNTS & KEYS — for each service used:
   - Exact config location (lib/core/config.dart or .env)
   - What the key is, WHERE to get it (with URL to dashboard)
   - Test/sandbox values if available
3. FIREBASE SETUP (if used):
   - Create Firebase project steps
   - Download google-services.json / GoogleService-Info.plist
   - Enable required services (Auth, Firestore, etc.)
4. PAYMENT PROVIDER SETUP (if e-commerce) — for EACH provider ({$payments}):
   - Mobile SDK setup steps (iOS/Android specific config)
   - Test mode instructions and test card numbers
   - Production switch checklist
5. BUILDING FOR PRODUCTION:
   - Android: signing, keystore, play store upload
   - iOS: certificates, provisioning profiles, app store
   - Web: flutter build web, deployment
6. ARCHITECTURE OVERVIEW — folder structure, state management pattern, data flow
7. COMMON TASKS — how to add a new screen, feature, API endpoint integration

Write for a JUNIOR developer. Explain mobile-specific concepts briefly.
PROMPT,
                verify: fn (): ?string => is_file($this->fullPath.'/SETUP.md') ? null : 'SETUP.md not created',
                skippable: true,
                timeout: 300,
                complexity: Complexity::SIMPLE,
            );
            $memory->completeStep('setup_md');
        } // end if !isStepDone('setup_md')

        $this->steps->printSummary();

        return true;
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
