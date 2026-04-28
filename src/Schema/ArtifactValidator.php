<?php

declare(strict_types=1);

namespace Tessera\Installer\Schema;

/**
 * Lightweight, dependency-free validator for Tessera's persistent artifacts.
 *
 * We don't pull in a full JSON-Schema engine — every artifact has a small,
 * well-known shape, and a 100-line validator catches the failure modes
 * that matter (wrong schema string, missing required keys, wrong type for
 * a top-level field). Deeper validation lives next to each consumer.
 *
 * This is intentionally permissive about *unknown* keys: an older reader
 * encountering a v1.1 payload from a newer writer should not blow up just
 * because there are extra fields. Hard incompatibilities go to v2.
 */
final class ArtifactValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<string> empty list = valid
     */
    public function validate(string $expectedSchema, array $payload): array
    {
        $errors = [];

        $actual = $payload['schema'] ?? null;
        if (! is_string($actual)) {
            return ['Missing top-level "schema" string field.'];
        }

        if ($actual !== $expectedSchema) {
            $errors[] = "Schema mismatch: expected '{$expectedSchema}', got '{$actual}'.";

            return $errors;
        }

        if (SchemaVersion::parse($actual) === null) {
            $errors[] = "Schema '{$actual}' is not in the tessera.<artifact>/v<N> format.";
        }

        foreach ($this->requiredKeys($expectedSchema) as $key => $expectedType) {
            if (! array_key_exists($key, $payload)) {
                $errors[] = "Missing required key '{$key}'.";

                continue;
            }

            if (! $this->matchesType($payload[$key], $expectedType)) {
                $actualType = get_debug_type($payload[$key]);
                $errors[] = "Key '{$key}' must be {$expectedType}, got {$actualType}.";
            }
        }

        return $errors;
    }

    /**
     * Sanity-check requirements per artifact. Permissive — only the fields
     * a downstream consumer actually depends on.
     *
     * @return array<string, string>
     */
    private function requiredKeys(string $schema): array
    {
        return match ($schema) {
            SchemaVersion::STATE => [
                'project' => 'string',
                'stack' => 'string',
                'status' => 'string',
            ],
            SchemaVersion::EVENT_LOG_ENTRY => [
                'type' => 'string',
                'occurred_at' => 'string',
                'trace_id' => 'string',
                'payload' => 'array',
            ],
            SchemaVersion::PLAN => [
                'stack' => 'string',
                'steps' => 'array',
                'plan_hash' => 'string',
            ],
            SchemaVersion::CACHED_RESPONSE => [
                'prompt_fingerprint' => 'string',
                'adapter' => 'string',
                'response' => 'array',
            ],
            SchemaVersion::GATE_RESULT => [
                'gate' => 'string',
                'step' => 'string',
                'passed' => 'bool',
            ],
            SchemaVersion::STACK_MANIFEST => [
                'name' => 'string',
                'steps' => 'array',
            ],
            default => [],
        };
    }

    private function matchesType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'int' => is_int($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'list' => is_array($value) && array_is_list($value),
            default => true,
        };
    }
}
