<?php

declare(strict_types=1);

namespace Tessera\Installer\Manifest;

use Tessera\Installer\Complexity;

/**
 * One step inside a stack manifest, before compilation.
 *
 * Manifest steps are the *authoring* shape — what the developer writes
 * in YAML. They differ from the executable PlanStep in two ways:
 *
 *   1. Prompt body MAY be inline OR referenced via `prompt_ref` (deferred
 *      until S2 prompt extraction). v1 only handles inline.
 *   2. Gates are typed loosely (associative arrays). The Gate engine in
 *      S2 will validate them strictly.
 *
 * The intent is forward-compat: a v1 manifest stays valid in v2 readers.
 */
final readonly class ManifestStep
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
        public string $promptVersion = '1',
        public ?string $adapterHint = null,
        public ?string $modelHint = null,
        public array $dependencies = [],
        public array $gates = [],
        public bool $skippable = false,
        public int $timeout = 600,
    ) {}
}
