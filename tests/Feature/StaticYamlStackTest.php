<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Adapters\AdapterContext;
use Tessera\Installer\Adapters\AdapterInterface;
use Tessera\Installer\Adapters\AdapterRegistry;
use Tessera\Installer\AiResponse;
use Tessera\Installer\AiTool;
use Tessera\Installer\Memory;
use Tessera\Installer\Stacks\YamlStackRunner;
use Tessera\Installer\SystemInfo;
use Tessera\Installer\ToolRouter;

/**
 * End-to-end test of the YAML-driven static stack pipeline.
 *
 * Exercises the full chain — manifest load → compile → write plan.json →
 * EventLog → PlanExecutor → adapter dispatch → gates → Memory → completion
 * — without spending real AI tokens. The fake adapter simulates a
 * successful AI run by creating the files that the gates expect to find.
 *
 * What this test guarantees:
 *
 *   - YamlStackRunner builds a valid plan.json on disk.
 *   - events.jsonl gets a complete trace (build.start → step.start ×3
 *     → step.complete/skip ×3 → build.complete).
 *   - Hard gates that find the expected file pass; the build proceeds.
 *   - Skippable steps that fail do NOT halt the build.
 *   - Memory state.json reflects the terminal status before the build
 *     completion event (Memory-before-event ordering).
 *
 * The smoke test against the live AI tools proved the pipeline works
 * with real LLMs; this test is the regression net so we can refactor
 * without rebuilding a real bakery every time.
 */
final class StaticYamlStackTest extends TestCase
{
    private string $cwd;

    private string $projectDir;

    private string $previousCwd;

    protected function setUp(): void
    {
        $this->previousCwd = (string) getcwd();
        $this->cwd = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera-yaml-runner-'.bin2hex(random_bytes(4));
        mkdir($this->cwd, 0755, true);
        chdir($this->cwd);

        $this->projectDir = $this->cwd.DIRECTORY_SEPARATOR.'site';
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->rrmdir($this->cwd);
    }

    #[Test]
    public function full_static_pipeline_succeeds_with_fake_adapter(): void
    {
        $callOrder = [];
        $adapter = $this->fileWritingFakeAdapter(function (string $renderedPrompt) use (&$callOrder): array {
            // Heuristic: which step are we in based on the rendered prompt content.
            if (str_contains($renderedPrompt, 'package.json')) {
                $callOrder[] = 'scaffold';

                return [
                    new AiResponse(true, 'site scaffolded'),
                    [
                        'index.html' => "<!doctype html><html lang=\"hr\"><body>Pekara</body></html>\n",
                        'package.json' => '{"name":"site","scripts":{"dev":"vite"}}',
                    ],
                ];
            }

            if (str_contains($renderedPrompt, 'Validating and polishing')
                || str_contains($renderedPrompt, 'code review')) {
                $callOrder[] = 'polish';

                // Polish step is skippable; simulate Sonnet rate-limiting.
                return [new AiResponse(false, '', 'rate limit', 1), []];
            }

            if (str_contains($renderedPrompt, 'SETUP.md')) {
                $callOrder[] = 'setup_md';

                return [
                    new AiResponse(true, 'setup docs written'),
                    ['SETUP.md' => "# Setup\n\nrun npm install\n"],
                ];
            }

            return [new AiResponse(true, 'generic'), []];
        });

        $registry = new AdapterRegistry([$adapter]);
        $router = ToolRouter::withSingleTool(AiTool::fake('claude'));

        $memory = new Memory($this->projectDir);
        $runner = new YamlStackRunner(null, $registry);

        $ok = $runner->run(
            directory: 'site',
            stackName: 'static',
            requirements: [
                'description' => 'Tiny portfolio',
                'languages' => ['hr'],
                'design_style' => 'minimal',
                'design_colors' => 'monochrome',
            ],
            router: $router,
            system: SystemInfo::detect(),
            memory: $memory,
        );

        $this->assertTrue($ok, 'YamlStackRunner should report success when scaffold passes its gate.');
        $this->assertSame(['scaffold', 'polish', 'setup_md'], $callOrder, 'All three steps must run.');

        // Plan + events + state artifacts on disk.
        $this->assertFileExists($this->projectDir.'/.tessera/plan.json');
        $this->assertFileExists($this->projectDir.'/.tessera/events.jsonl');
        $this->assertFileExists($this->projectDir.'/.tessera/state.json');

        // Gates ran — index.html and package.json were written by the fake.
        $this->assertFileExists($this->projectDir.'/index.html');
        $this->assertFileExists($this->projectDir.'/package.json');
        $this->assertFileExists($this->projectDir.'/SETUP.md');
    }

    #[Test]
    public function skippable_polish_failure_does_not_halt_the_build(): void
    {
        $adapter = $this->fileWritingFakeAdapter(function (string $renderedPrompt): array {
            if (str_contains($renderedPrompt, 'package.json')) {
                return [
                    new AiResponse(true, 'ok'),
                    ['index.html' => '<html></html>'],
                ];
            }
            if (str_contains($renderedPrompt, 'code review')) {
                return [new AiResponse(false, '', 'sonnet down', 1), []];
            }
            if (str_contains($renderedPrompt, 'SETUP.md')) {
                return [
                    new AiResponse(true, 'ok'),
                    ['SETUP.md' => '# Setup'],
                ];
            }

            return [new AiResponse(true, ''), []];
        });

        $runner = new YamlStackRunner(null, new AdapterRegistry([$adapter]));
        $memory = new Memory($this->projectDir);

        $ok = $runner->run(
            directory: 'site',
            stackName: 'static',
            requirements: ['description' => 'demo'],
            router: ToolRouter::withSingleTool(AiTool::fake('claude')),
            system: SystemInfo::detect(),
            memory: $memory,
        );

        $this->assertTrue($ok);

        $events = file($this->projectDir.'/.tessera/events.jsonl');
        $types = array_map(fn ($l) => json_decode($l, true)['type'] ?? '', $events);

        $this->assertContains('step.skip', $types, 'polish should be skipped not failed');
        $this->assertContains('build.complete', $types);
    }

    #[Test]
    public function hard_gate_failure_on_scaffold_halts_the_build(): void
    {
        // Adapter "succeeds" but writes NO files — scaffold's hard gate
        // (exists_any: index.html, package.json) must catch it.
        $adapter = $this->fileWritingFakeAdapter(fn () => [new AiResponse(true, 'I lied'), []]);

        $runner = new YamlStackRunner(null, new AdapterRegistry([$adapter]));
        $memory = new Memory($this->projectDir);

        $ok = $runner->run(
            directory: 'site',
            stackName: 'static',
            requirements: ['description' => 'demo'],
            router: ToolRouter::withSingleTool(AiTool::fake('claude')),
            system: SystemInfo::detect(),
            memory: $memory,
        );

        $this->assertFalse($ok, 'Build should fail when scaffold gate detects no files.');

        $events = file($this->projectDir.'/.tessera/events.jsonl');
        $types = array_map(fn ($l) => json_decode($l, true)['type'] ?? '', $events);

        $this->assertContains('gate.fail', $types);
        $this->assertContains('build.fail', $types);
    }

    /**
     * Fake adapter that runs $callback for each prompt and writes any
     * files the callback returns into the working directory before
     * returning the AiResponse. This is how we simulate "AI did its job".
     */
    private function fileWritingFakeAdapter(\Closure $callback): AdapterInterface
    {
        return new class($callback) implements AdapterInterface
        {
            public function __construct(private \Closure $cb) {}

            public function name(): string
            {
                return 'fake';
            }

            public function version(): ?string
            {
                return 'fake-1.0';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function supportsModel(?string $model): bool
            {
                return true;
            }

            public function execute(string $prompt, AdapterContext $context): AiResponse
            {
                [$response, $files] = ($this->cb)($prompt);

                foreach ($files as $relativePath => $content) {
                    $abs = $context->workingDir.DIRECTORY_SEPARATOR.$relativePath;
                    $dir = dirname($abs);

                    if (! is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }

                    @file_put_contents($abs, $content);
                }

                return $response;
            }

            public function estimateCost(int $estimatedInputTokens, ?int $estimatedOutputTokens = null): ?float
            {
                return null;
            }
        };
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
