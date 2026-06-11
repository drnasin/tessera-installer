<?php

declare(strict_types=1);

namespace Tessera\Installer\Manifest;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Tessera\Installer\Complexity;

/**
 * Reads and validates `stacks/<name>.yaml` files.
 *
 * The YAML shape is intentionally narrow:
 *
 *   name: laravel
 *   label: "Laravel + Filament"
 *   description: "..."
 *   manifest_version: "1"
 *   requires: [php, composer]
 *   steps:
 *     - id: models
 *       name: "Generate Eloquent models"
 *       complexity: complex
 *       prompt: |
 *         Build models for...
 *       prompt_version: "1"
 *       adapter_hint: claude
 *       model_hint: claude-opus-4-8
 *       dependencies: []
 *       gates:
 *         - type: exists_any
 *           patterns: [app/Models/*.php]
 *
 * Validation philosophy: reject early, reject loud. The parser refuses
 * unknown top-level keys (forward-compat would mask typos), but is
 * permissive about unknown keys inside `gates` because the Gate taxonomy
 * is still evolving in S2.
 */
final class StackManifestLoader
{
    private const ALLOWED_TOP_LEVEL_KEYS = [
        'name',
        'label',
        'description',
        'manifest_version',
        'requires',
        'steps',
    ];

    private const ALLOWED_STEP_KEYS = [
        'id',
        'name',
        'complexity',
        'prompt',
        'prompt_version',
        'adapter_hint',
        'model_hint',
        'dependencies',
        'gates',
        'skippable',
        'timeout',
    ];

    /**
     * Gate types the loader accepts. This list MUST stay in lockstep with the
     * types {@see \Tessera\Installer\Plan\GateEvaluator::evaluate()} actually
     * implements — a type allowed here but unimplemented there would compile a
     * manifest that then fails at execution with "Unknown gate type". The
     * GateValidationParityTest enforces this invariant.
     *
     * @var list<string>
     */
    public const ALLOWED_GATE_TYPES = [
        'exists_any',
        'exists_all',
    ];

    public function loadFromFile(string $path): StackManifest
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Manifest file not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read manifest: {$path}");
        }

        return $this->loadFromString($raw, $path);
    }

    public function loadFromString(string $yaml, string $sourceLabel = '<inline>'): StackManifest
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new \RuntimeException("YAML parse error in {$sourceLabel}: ".$e->getMessage(), 0, $e);
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \RuntimeException("Manifest {$sourceLabel} must be a YAML mapping at the root.");
        }

        $this->rejectUnknownKeys($data, self::ALLOWED_TOP_LEVEL_KEYS, "{$sourceLabel} (top-level)");

        foreach (['name', 'label', 'description', 'steps'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new \RuntimeException("Manifest {$sourceLabel} missing required key '{$required}'.");
            }
        }

        if (! is_array($data['steps']) || $data['steps'] === []) {
            throw new \RuntimeException("Manifest {$sourceLabel} must declare at least one step.");
        }

        $steps = [];
        foreach ($data['steps'] as $idx => $rawStep) {
            $steps[] = $this->buildStep($rawStep, "{$sourceLabel} step #{$idx}");
        }

        return new StackManifest(
            name: (string) $data['name'],
            label: (string) $data['label'],
            description: (string) $data['description'],
            steps: $steps,
            manifestVersion: isset($data['manifest_version']) ? (string) $data['manifest_version'] : '1',
            requires: array_values(array_map('strval', $data['requires'] ?? [])),
        );
    }

    /**
     * @param  array<string, mixed>  $rawStep
     */
    private function buildStep(mixed $rawStep, string $context): ManifestStep
    {
        if (! is_array($rawStep)) {
            throw new \RuntimeException("Step in {$context} must be a YAML mapping.");
        }

        $this->rejectUnknownKeys($rawStep, self::ALLOWED_STEP_KEYS, $context);

        foreach (['id', 'complexity', 'prompt'] as $required) {
            if (! array_key_exists($required, $rawStep)) {
                throw new \RuntimeException("Step {$context} missing required key '{$required}'.");
            }
        }

        $complexityRaw = (string) $rawStep['complexity'];
        $complexity = Complexity::tryFrom($complexityRaw);
        if ($complexity === null) {
            throw new \RuntimeException(
                "Step {$context}: complexity '{$complexityRaw}' is not one of: simple, medium, complex.",
            );
        }

        $gates = array_values($rawStep['gates'] ?? []);
        foreach ($gates as $gateIdx => $gate) {
            if (! is_array($gate) || ! isset($gate['type'])) {
                throw new \RuntimeException("Step {$context}: gate #{$gateIdx} must be a mapping with a 'type' key.");
            }

            $gateType = (string) $gate['type'];
            if (! in_array($gateType, self::ALLOWED_GATE_TYPES, true)) {
                throw new \RuntimeException(
                    "Step {$context}: unknown gate type '{$gateType}'. Allowed: ".implode(', ', self::ALLOWED_GATE_TYPES),
                );
            }
        }

        return new ManifestStep(
            id: (string) $rawStep['id'],
            name: (string) ($rawStep['name'] ?? $rawStep['id']),
            complexity: $complexity,
            prompt: (string) $rawStep['prompt'],
            promptVersion: isset($rawStep['prompt_version']) ? (string) $rawStep['prompt_version'] : '1',
            adapterHint: isset($rawStep['adapter_hint']) ? (string) $rawStep['adapter_hint'] : null,
            modelHint: isset($rawStep['model_hint']) ? (string) $rawStep['model_hint'] : null,
            dependencies: array_values(array_map('strval', $rawStep['dependencies'] ?? [])),
            gates: $gates,
            skippable: (bool) ($rawStep['skippable'] ?? false),
            timeout: (int) ($rawStep['timeout'] ?? 600),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function rejectUnknownKeys(array $data, array $allowed, string $context): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new \RuntimeException(
                    "Manifest {$context}: unknown key '{$key}'. Allowed: ".implode(', ', $allowed),
                );
            }
        }
    }
}
