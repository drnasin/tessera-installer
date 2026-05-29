<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Manifest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tessera\Installer\Complexity;
use Tessera\Installer\Manifest\StackManifestLoader;

final class StackManifestLoaderTest extends TestCase
{
    #[Test]
    public function loads_minimal_valid_manifest(): void
    {
        $manifest = (new StackManifestLoader)->loadFromString(<<<YAML
            name: tiny
            label: "Tiny"
            description: "A tiny demo stack"
            steps:
              - id: hello
                complexity: simple
                prompt: "say hi"
            YAML);

        $this->assertSame('tiny', $manifest->name);
        $this->assertSame('Tiny', $manifest->label);
        $this->assertCount(1, $manifest->steps);
        $this->assertSame(Complexity::SIMPLE, $manifest->steps[0]->complexity);
        $this->assertSame('say hi', $manifest->steps[0]->prompt);
    }

    #[Test]
    public function rejects_unknown_top_level_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("unknown key 'extra'");

        (new StackManifestLoader)->loadFromString(<<<YAML
            name: tiny
            label: "Tiny"
            description: "x"
            extra: oops
            steps:
              - id: hello
                complexity: simple
                prompt: "x"
            YAML);
    }

    #[Test]
    public function rejects_unknown_step_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("unknown key 'priority'");

        (new StackManifestLoader)->loadFromString(<<<YAML
            name: tiny
            label: "Tiny"
            description: "x"
            steps:
              - id: hello
                complexity: simple
                prompt: "x"
                priority: high
            YAML);
    }

    #[Test]
    public function rejects_invalid_complexity(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("not one of");

        (new StackManifestLoader)->loadFromString(<<<YAML
            name: tiny
            label: "Tiny"
            description: "x"
            steps:
              - id: hello
                complexity: epic
                prompt: "x"
            YAML);
    }

    #[Test]
    public function rejects_missing_required_top_level_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("missing required key 'description'");

        (new StackManifestLoader)->loadFromString(<<<YAML
            name: tiny
            label: "Tiny"
            steps:
              - id: hello
                complexity: simple
                prompt: "x"
            YAML);
    }

    #[Test]
    public function rejects_empty_steps_list(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("at least one step");

        (new StackManifestLoader)->loadFromString(<<<YAML
            name: tiny
            label: "Tiny"
            description: "x"
            steps: []
            YAML);
    }

    #[Test]
    public function preserves_dependencies_and_hints(): void
    {
        $manifest = (new StackManifestLoader)->loadFromString(<<<YAML
            name: chained
            label: "Chained"
            description: "x"
            steps:
              - id: a
                complexity: simple
                prompt: "A"
              - id: b
                complexity: medium
                prompt: "B"
                adapter_hint: claude
                model_hint: claude-sonnet-4
                dependencies: [a]
            YAML);

        $this->assertSame(['a'], $manifest->steps[1]->dependencies);
        $this->assertSame('claude', $manifest->steps[1]->adapterHint);
        $this->assertSame('claude-sonnet-4', $manifest->steps[1]->modelHint);
    }

    #[Test]
    public function gates_pass_through_as_arrays(): void
    {
        // The loader validates gate *types* but is intentionally permissive
        // about extra keys inside a gate (e.g. min_count) so the Gate taxonomy
        // can keep evolving. This proves known types pass through and that an
        // arbitrary extra key (min_count) survives into the parsed gate.
        $manifest = (new StackManifestLoader)->loadFromString(<<<YAML
            name: gated
            label: "Gated"
            description: "x"
            steps:
              - id: a
                complexity: simple
                prompt: "A"
                gates:
                  - type: exists_any
                    patterns: ["*.php"]
                    min_count: 3
                  - type: exists_all
            YAML);

        $this->assertCount(2, $manifest->steps[0]->gates);
        $this->assertSame('exists_any', $manifest->steps[0]->gates[0]['type']);
        $this->assertSame(3, $manifest->steps[0]->gates[0]['min_count']);
    }

    #[Test]
    public function rejects_unimplemented_gate_type(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("unknown gate type 'glob'");

        (new StackManifestLoader)->loadFromString(<<<YAML
            name: gated
            label: "Gated"
            description: "x"
            steps:
              - id: a
                complexity: simple
                prompt: "A"
                gates:
                  - type: glob
            YAML);
    }

    #[Test]
    public function rejects_root_that_is_not_a_mapping(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mapping at the root');

        (new StackManifestLoader)->loadFromString("- just\n- a\n- list");
    }

    #[Test]
    public function file_not_found_throws_clear_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        (new StackManifestLoader)->loadFromFile('/tmp/no-such-manifest-'.bin2hex(random_bytes(4)).'.yaml');
    }
}
