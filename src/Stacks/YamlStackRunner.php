<?php

declare(strict_types=1);

namespace Tessera\Installer\Stacks;

use Tessera\Installer\Adapters\AdapterRegistry;
use Tessera\Installer\Console;
use Tessera\Installer\EnvPolicy;
use Tessera\Installer\Events\EventLog;
use Tessera\Installer\Events\EventType;
use Tessera\Installer\Manifest\ManifestCompiler;
use Tessera\Installer\Manifest\StackManifestLoader;
use Tessera\Installer\Memory;
use Tessera\Installer\Plan\PlanCompiler;
use Tessera\Installer\Plan\PlanExecutor;
use Tessera\Installer\Plan\RenderContext;
use Tessera\Installer\SystemInfo;
use Tessera\Installer\ToolRouter;

/**
 * Drop-in replacement for `*Stack::scaffold()` that runs the new YAML
 * + plan + adapter pipeline.
 *
 * Each stack's PHP class keeps its lifecycle methods (preflight,
 * postSetup, completionInfo) and its scaffold() shrinks to a one-line
 * delegation here. Per round-3 consensus, lifecycle is too quirky to
 * push into YAML in Sprint 1; the manifest describes the AI build
 * pipeline only.
 *
 * Inputs:
 *   - $directory: relative project directory under cwd
 *   - $stackName: matches a YAML file under installer/stacks/
 *   - $requirements: extracted from interactive Q&A or from
 *     --requirements-fixture
 *   - $router: legacy ToolRouter, used by AdapterSelector to honour
 *     complexity routing during the v3.x → v4.0 transition
 *   - $system: SystemInfo, hands the systemContext into RenderContext
 *   - $memory: state.json owner; resume short-circuits already-completed
 *     steps inside PlanExecutor
 */
final class YamlStackRunner
{
    private string $stacksDir;

    private ?AdapterRegistry $adapters;

    /**
     * Both arguments are optional for production paths — defaults map to
     * the bundled stack manifests and the default Adapter set. Tests
     * inject a fake registry to drive the full flow without spending
     * real AI tokens.
     */
    public function __construct(?string $stacksDir = null, ?AdapterRegistry $adapters = null)
    {
        $this->stacksDir = $stacksDir ?? dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stacks';
        $this->adapters = $adapters;
    }

    public function run(
        string $directory,
        string $stackName,
        array $requirements,
        ToolRouter $router,
        SystemInfo $system,
        Memory $memory,
    ): bool {
        $fullPath = getcwd().DIRECTORY_SEPARATOR.$directory;

        if (! @mkdir($fullPath, 0755, true) && ! is_dir($fullPath)) {
            Console::error("Could not create directory: {$fullPath}");

            return false;
        }

        $manifestPath = $this->stacksDir.DIRECTORY_SEPARATOR.$stackName.'.yaml';

        if (! is_file($manifestPath)) {
            Console::error("Stack manifest not found: {$manifestPath}");

            return false;
        }

        if ($memory->hasState()) {
            $memory->updateContext($requirements, $system->buildAiContext());
        } else {
            $memory->init($directory, $stackName, $requirements, $system->buildAiContext());
        }

        try {
            $manifest = (new StackManifestLoader)->loadFromFile($manifestPath);
            $plan = (new ManifestCompiler)->compile($manifest, $requirements);
        } catch (\Throwable $e) {
            Console::error('Failed to compile plan: '.$e->getMessage());
            $memory->fail('Plan compile failed: '.$e->getMessage());

            return false;
        }

        // Persist the compiled plan so `tessera plan show` and replay
        // can inspect what was executed.
        $planPath = $fullPath.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'plan.json';
        (new PlanCompiler)->write($plan, $planPath);

        $eventLogPath = $fullPath.DIRECTORY_SEPARATOR.'.tessera'.DIRECTORY_SEPARATOR.'events.jsonl';
        $eventLog = new EventLog($eventLogPath, $memory->traceId());

        $eventLog->emit(EventType::BuildResume, [
            'stack' => $stackName,
            'plan_hash' => $plan->planHash,
            'event_log_path' => $eventLogPath,
        ]);

        $context = RenderContext::fromRequirements(
            $requirements,
            $system->buildAiContext(),
            $this->detectNodeVersion(),
            $this->detectGoVersion(),
            $this->detectStackVersions($fullPath),
            $memory->buildAiContext(),
            $this->detectFlutterVersion(),
        );

        $executor = new PlanExecutor(
            adapters: $this->adapters ?? AdapterRegistry::default(),
            eventLog: $eventLog,
            router: $router,
            memory: $memory,
        );

        Console::line();
        Console::bold("Building '{$stackName}' — ".count($plan->steps).' steps');
        Console::line('  plan hash:  '.substr($plan->planHash, 0, 16).'…');
        Console::line('  trace_id:   '.$eventLog->traceId());
        Console::line();

        $result = $executor->execute($plan, $fullPath, $context);

        Console::line();
        if ($result->success) {
            Console::success("Build complete in {$result->totalDurationMs}ms");
        } else {
            $failed = $result->failedSteps();
            $reason = $failed === [] ? 'unknown' : $failed[0]->errorMessage;
            Console::error("Build halted: {$reason}");
        }

        return $result->success;
    }

    private function detectNodeVersion(): string
    {
        $node = Console::execSilentArgv(['node', '--version'], env: EnvPolicy::minimal());

        if ($node['exit'] === 0) {
            return 'Node.js '.trim($node['output']);
        }

        return 'Node.js (latest)';
    }

    private function detectGoVersion(): string
    {
        $go = Console::execSilentArgv(['go', 'version'], env: EnvPolicy::minimal());

        if ($go['exit'] === 0) {
            return trim($go['output']);
        }

        return 'Go (latest)';
    }

    private function detectFlutterVersion(): string
    {
        $flutter = Console::execSilentArgv(['flutter', '--version'], env: EnvPolicy::minimal());

        if ($flutter['exit'] === 0) {
            $first = strtok((string) $flutter['output'], "\n");
            if ($first !== false) {
                return trim($first);
            }
        }

        return 'Flutter (latest)';
    }

    /**
     * Build a multi-line "Stack X: vN.N" digest for stacks where multiple
     * tool versions matter (Laravel: PHP / Composer / Laravel framework).
     * Each detection is best-effort; missing tools just drop their line.
     */
    private function detectStackVersions(string $projectPath): string
    {
        $lines = [];

        $php = Console::execSilentArgv(['php', '--version'], env: EnvPolicy::minimal());
        if ($php['exit'] === 0) {
            $first = strtok((string) $php['output'], "\n");
            if ($first !== false) {
                $lines[] = trim($first);
            }
        }

        $composer = Console::execSilentArgv(['composer', '--version'], env: EnvPolicy::minimal());
        if ($composer['exit'] === 0) {
            $lines[] = trim((string) $composer['output']);
        }

        if (is_file($projectPath.'/composer.lock')) {
            $lock = @file_get_contents($projectPath.'/composer.lock');
            if (is_string($lock) && preg_match('/"name":\s*"laravel\/framework",\s*"version":\s*"v?([^"]+)"/', $lock, $m) === 1) {
                $lines[] = 'Laravel '.$m[1];
            }
        }

        return implode("\n", $lines);
    }
}
