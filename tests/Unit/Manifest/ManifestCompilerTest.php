<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Manifest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Manifest\ManifestCompiler;
use Tessera\Installer\Manifest\StackManifestLoader;

final class ManifestCompilerTest extends TestCase
{
    #[Test]
    public function compiles_manifest_into_plan_with_matching_step_ids(): void
    {
        $manifest = $this->loadManifest();
        $plan = (new ManifestCompiler)->compile($manifest);

        $manifestIds = array_map(fn ($s): string => $s->id, $manifest->steps);
        $planIds = array_map(fn ($s): string => $s->id, $plan->steps);

        $this->assertSame($manifestIds, $planIds);
    }

    #[Test]
    public function plan_hash_is_deterministic(): void
    {
        $manifest = $this->loadManifest();

        $first = (new ManifestCompiler)->compile($manifest);
        $second = (new ManifestCompiler)->compile($manifest);

        $this->assertSame($first->planHash, $second->planHash);
    }

    #[Test]
    public function plan_hash_changes_when_prompt_body_changes(): void
    {
        $base = (new ManifestCompiler)->compile($this->loadManifest());

        $modified = (new StackManifestLoader)->loadFromString(<<<YAML
            name: chain
            label: "Chain"
            description: "x"
            steps:
              - id: a
                complexity: simple
                prompt: "DIFFERENT BODY"
              - id: b
                complexity: medium
                prompt: "B body"
                dependencies: [a]
            YAML);

        $modifiedPlan = (new ManifestCompiler)->compile($modified);

        $this->assertNotSame($base->planHash, $modifiedPlan->planHash);
    }

    #[Test]
    public function plan_hash_invariant_when_step_name_changes(): void
    {
        $base = (new ManifestCompiler)->compile($this->loadManifest());

        $renamed = (new StackManifestLoader)->loadFromString(<<<YAML
            name: chain
            label: "Chain"
            description: "x"
            steps:
              - id: a
                name: "A renamed"
                complexity: simple
                prompt: "A body"
              - id: b
                name: "B renamed"
                complexity: medium
                prompt: "B body"
                dependencies: [a]
            YAML);

        $renamedPlan = (new ManifestCompiler)->compile($renamed);

        $this->assertSame($base->planHash, $renamedPlan->planHash);
    }

    #[Test]
    public function requirements_propagate_into_plan(): void
    {
        $manifest = $this->loadManifest();

        $plan = (new ManifestCompiler)->compile($manifest, ['languages' => ['hr', 'en']]);

        $this->assertSame(['hr', 'en'], $plan->requirements['languages']);
    }

    private function loadManifest(): \Tessera\Installer\Manifest\StackManifest
    {
        return (new StackManifestLoader)->loadFromString(<<<YAML
            name: chain
            label: "Chain"
            description: "x"
            steps:
              - id: a
                complexity: simple
                prompt: "A body"
              - id: b
                complexity: medium
                prompt: "B body"
                dependencies: [a]
            YAML);
    }
}
