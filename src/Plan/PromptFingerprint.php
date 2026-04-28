<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

/**
 * Stable, comparable identity for a prompt template.
 *
 * The fingerprint is `sha256(promptVersion + ":" + promptBody)`. The version
 * prefix means a non-content change — e.g., bumping a prompt's semantic
 * version because we deliberately want a re-render even though the text is
 * the same — produces a new hash without text edits. Conversely, accidental
 * whitespace changes flip the hash, which is exactly what we want for
 * `tessera plan diff` to surface as a real change.
 *
 * Two fingerprints with the same hash are interchangeable for replay; cached
 * AI responses are keyed by it. Two with different hashes are different
 * prompts, even if their text looks similar.
 *
 * Stored as a value object (no I/O). Plan compilers use it to anchor
 * PlanStep::promptFingerprint; replay/cache layers use it as the cache key.
 */
final readonly class PromptFingerprint
{
    public string $hash;

    public string $shortHash;

    public function __construct(
        public string $body,
        public string $version = '1',
    ) {
        $this->hash = hash('sha256', $version.':'.$body);
        $this->shortHash = substr($this->hash, 0, 12);
    }

    public function matches(string $hash): bool
    {
        return hash_equals($this->hash, $hash);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->hash, $other->hash);
    }

    /**
     * Compact display form: short hash + version, e.g. "ab12cd34ef56@v1".
     */
    public function display(): string
    {
        return $this->shortHash.'@v'.$this->version;
    }
}
