<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Plan;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Manifest\StackManifestLoader;
use Tessera\Installer\Plan\GateEvaluator;

/**
 * Guards the validation/execution contract between the manifest loader and the
 * gate executor: every gate type the loader accepts MUST be implemented by
 * GateEvaluator. Without this, a manifest can compile and then fail at runtime
 * with "Unknown gate type" — the exact mismatch issue #6 closes.
 */
final class GateValidationParityTest extends TestCase
{
    private string $workingDir;

    protected function setUp(): void
    {
        $this->workingDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tessera-parity-'.bin2hex(random_bytes(4));
        mkdir($this->workingDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workingDir)) {
            foreach (glob($this->workingDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->workingDir);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedGateTypes(): iterable
    {
        foreach (StackManifestLoader::ALLOWED_GATE_TYPES as $type) {
            yield $type => [$type];
        }
    }

    /**
     * Reads the loader's real allow-list (not a hardcoded copy) so re-adding an
     * unimplemented type to ALLOWED_GATE_TYPES makes this test fail.
     */
    #[Test]
    #[DataProvider('allowedGateTypes')]
    public function every_allowed_gate_type_is_executable_by_the_evaluator(string $type): void
    {
        $results = (new GateEvaluator)->evaluate('parity', [
            ['type' => $type],
        ], $this->workingDir);

        // The gate may legitimately fail on this bare spec (e.g. missing
        // 'patterns'), but it must NOT route to the "Unknown gate type"
        // default branch — that is the compiles-then-fails-as-unknown trap.
        $this->assertStringNotContainsString(
            'Unknown gate type',
            $results[0]->message,
            "Loader allows gate type '{$type}' but GateEvaluator does not implement it.",
        );
    }

    #[Test]
    public function allow_list_contains_only_implemented_types(): void
    {
        $this->assertSame(
            ['exists_any', 'exists_all'],
            StackManifestLoader::ALLOWED_GATE_TYPES,
            'ALLOWED_GATE_TYPES drifted from the set GateEvaluator implements.',
        );
    }

    #[Test]
    public function each_allowed_type_has_a_passing_path(): void
    {
        touch($this->workingDir.'/marker.txt');

        $passing = [
            'exists_any' => ['type' => 'exists_any', 'patterns' => ['marker.txt']],
            'exists_all' => ['type' => 'exists_all', 'paths' => ['marker.txt']],
        ];

        foreach (StackManifestLoader::ALLOWED_GATE_TYPES as $type) {
            $this->assertArrayHasKey($type, $passing, "No passing-path assertion for allowed gate type '{$type}'.");

            $results = (new GateEvaluator)->evaluate('parity', [$passing[$type]], $this->workingDir);

            $this->assertTrue($results[0]->passed, "Gate type '{$type}' should pass when its target exists.");
        }
    }

    #[Test]
    public function each_allowed_type_has_a_failing_path(): void
    {
        $failing = [
            'exists_any' => ['type' => 'exists_any', 'patterns' => ['nope.txt']],
            'exists_all' => ['type' => 'exists_all', 'paths' => ['nope.txt']],
        ];

        foreach (StackManifestLoader::ALLOWED_GATE_TYPES as $type) {
            $this->assertArrayHasKey($type, $failing, "No failing-path assertion for allowed gate type '{$type}'.");

            $results = (new GateEvaluator)->evaluate('parity', [$failing[$type]], $this->workingDir);

            $this->assertFalse($results[0]->passed, "Gate type '{$type}' should fail when its target is missing.");
        }
    }
}
