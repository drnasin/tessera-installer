<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Manifest\ManifestCompiler;
use Tessera\Installer\Manifest\StackManifestLoader;

/**
 * Snapshot/contract tests for the bundled stack manifests.
 *
 * For every YAML in `stacks/`:
 *   - Loader parses it with no errors.
 *   - Compiler turns it into a CompiledPlan whose hash is valid.
 *   - All declared dependencies resolve (compiler enforces this).
 *   - The plan contains the step ids the contract requires.
 *
 * The required-step list is the smallest set that "this stack delivers
 * what it advertises". Adding more steps does not break the contract;
 * removing a required step does. This is the test that fires when
 * someone accidentally deletes the `setup_md` step from a stack.
 */
final class StackContractTest extends TestCase
{
    private static string $stacksDir;

    public static function setUpBeforeClass(): void
    {
        self::$stacksDir = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stacks';
    }

    #[Test]
    #[DataProvider('stackContracts')]
    public function manifest_compiles_into_valid_plan(string $stackName, array $requiredStepIds): void
    {
        $path = self::$stacksDir.DIRECTORY_SEPARATOR.$stackName.'.yaml';
        $this->assertFileExists($path, "Stack manifest {$stackName}.yaml is missing.");

        $manifest = (new StackManifestLoader)->loadFromFile($path);
        $plan = (new ManifestCompiler)->compile($manifest);

        $this->assertTrue($plan->isHashValid(), "Plan hash invalid for {$stackName}.");
        $this->assertSame($stackName, $plan->stack);

        $planStepIds = array_map(fn ($s): string => $s->id, $plan->steps);

        foreach ($requiredStepIds as $required) {
            $this->assertContains(
                $required,
                $planStepIds,
                "Stack '{$stackName}' is missing required step '{$required}'.",
            );
        }
    }

    #[Test]
    #[DataProvider('stackContracts')]
    public function plan_hash_is_stable_across_compiles(string $stackName): void
    {
        $path = self::$stacksDir.DIRECTORY_SEPARATOR.$stackName.'.yaml';
        $manifest = (new StackManifestLoader)->loadFromFile($path);

        $a = (new ManifestCompiler)->compile($manifest);
        $b = (new ManifestCompiler)->compile($manifest);

        $this->assertSame($a->planHash, $b->planHash, "Plan hash for {$stackName} should be deterministic.");
    }

    #[Test]
    #[DataProvider('stackContracts')]
    public function plan_steps_topologically_sort_without_cycle(string $stackName): void
    {
        $path = self::$stacksDir.DIRECTORY_SEPARATOR.$stackName.'.yaml';
        $manifest = (new StackManifestLoader)->loadFromFile($path);
        $plan = (new ManifestCompiler)->compile($manifest);

        $sorted = $plan->inTopologicalOrder();

        $this->assertCount(count($plan->steps), $sorted);
    }

    public static function stackContracts(): array
    {
        return [
            'laravel' => ['laravel', ['core_models', 'theme', 'admin', 'content', 'tests', 'setup_md']],
            'node' => ['node', ['scaffold', 'tests', 'tests_fixed', 'setup_md']],
            'go' => ['go', ['scaffold', 'tests', 'tests_fixed', 'setup_md']],
            'flutter' => ['flutter', ['scaffold', 'tests', 'tests_fixed', 'setup_md']],
            'static' => ['static', ['scaffold', 'polish', 'setup_md']],
        ];
    }
}
