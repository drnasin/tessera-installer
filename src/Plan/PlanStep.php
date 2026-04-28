<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

use Tessera\Installer\Complexity;

/**
 * One executable unit inside a CompiledPlan.
 *
 * Steps carry everything an executor needs to dispatch the work without
 * looking at external state: the prompt body (for inline execution) and
 * its fingerprint (for cache lookup, replay, and diffing). Adapter and
 * model are HINTS — the router still has the final say at execution
 * time, especially when the hinted adapter is rate-limited or down.
 *
 * Gates are stored as raw associative arrays in v1 — the Gate taxonomy
 * (hard / soft / retryable / human-review) lands in S2 with a typed Gate
 * value object. The shape today is permissive on purpose so plan.json
 * written today still parses with the v2 reader.
 *
 * Dependencies are step ids that must complete before this one starts.
 * The v1 executor walks them topologically and runs sequentially;
 * parallel branches are S3.
 */
final readonly class PlanStep
{
    /**
     * @param  list<string>  $dependencies
     * @param  list<array<string, mixed>>  $gates
     */
    public function __construct(
        public string $id,
        public string $name,
        public Complexity $complexity,
        public string $prompt,
        public string $promptFingerprint,
        public ?string $adapterHint = null,
        public ?string $modelHint = null,
        public array $dependencies = [],
        public array $gates = [],
        public bool $skippable = false,
        public int $timeout = 600,
    ) {}

    /**
     * Build a step and derive the prompt fingerprint from its body.
     *
     * Note: `$prompt` MAY contain `{{var}}` placeholders. The fingerprint is
     * computed over the *template* (placeholders included). At execution
     * time PromptRenderer substitutes the placeholders against a
     * RenderContext; rendered prompts get a separate hash recorded in the
     * event log alongside this template fingerprint.
     */
    public static function build(
        string $id,
        string $name,
        Complexity $complexity,
        string $prompt,
        string $promptVersion = '1',
        ?string $adapterHint = null,
        ?string $modelHint = null,
        array $dependencies = [],
        array $gates = [],
        bool $skippable = false,
        int $timeout = 600,
    ): self {
        $fingerprint = new PromptFingerprint($prompt, $promptVersion);

        return new self(
            id: $id,
            name: $name,
            complexity: $complexity,
            prompt: $prompt,
            promptFingerprint: $fingerprint->hash,
            adapterHint: $adapterHint,
            modelHint: $modelHint,
            dependencies: $dependencies,
            gates: $gates,
            skippable: $skippable,
            timeout: $timeout,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'complexity' => $this->complexity->value,
            'prompt' => $this->prompt,
            'prompt_fingerprint' => $this->promptFingerprint,
            'adapter_hint' => $this->adapterHint,
            'model_hint' => $this->modelHint,
            'dependencies' => $this->dependencies,
            'gates' => $this->gates,
            'skippable' => $this->skippable,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) ($data['name'] ?? $data['id']),
            complexity: Complexity::from((string) $data['complexity']),
            prompt: (string) $data['prompt'],
            promptFingerprint: (string) $data['prompt_fingerprint'],
            adapterHint: isset($data['adapter_hint']) ? (string) $data['adapter_hint'] : null,
            modelHint: isset($data['model_hint']) ? (string) $data['model_hint'] : null,
            dependencies: array_values(array_map('strval', $data['dependencies'] ?? [])),
            gates: array_values($data['gates'] ?? []),
            skippable: (bool) ($data['skippable'] ?? false),
            timeout: (int) ($data['timeout'] ?? 600),
        );
    }
}
