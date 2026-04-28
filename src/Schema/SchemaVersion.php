<?php

declare(strict_types=1);

namespace Tessera\Installer\Schema;

/**
 * Versioned identifiers for every persistent artifact Tessera writes.
 *
 * Every JSON file the installer creates — state.json, events.jsonl entries,
 * plan.json, cached AI responses, gate results — carries a `schema` field
 * with one of these constants. Readers MUST refuse to parse an unknown
 * schema rather than guess the shape. This is the single seam that makes
 * the v1 → v2 cut clean: tooling that doesn't recognise v2 fails fast.
 *
 * The format is `tessera.<artifact>/v<N>`. The "tessera." namespace lets
 * third-party tooling (CI dashboards, post-mortem viewers) co-exist
 * without colliding with custom payloads.
 */
final class SchemaVersion
{
    /** Memory::save() output — `.tessera/state.json`. */
    public const STATE = 'tessera.state/v1';

    /** Single line in `.tessera/events.jsonl`. */
    public const EVENT_LOG_ENTRY = 'tessera.event/v1';

    /** Compiled, hash-anchored execution plan — `.tessera/plan.json`. */
    public const PLAN = 'tessera.plan/v1';

    /** A cached AI response for replay — `.tessera/cache/<hash>.json`. */
    public const CACHED_RESPONSE = 'tessera.cached-response/v1';

    /** Result of a single quality gate check. */
    public const GATE_RESULT = 'tessera.gate-result/v1';

    /** A parsed Stack-as-Code YAML manifest. */
    public const STACK_MANIFEST = 'tessera.stack/v1';

    /**
     * All known schema strings — useful for ArtifactValidator and tests.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::STATE,
            self::EVENT_LOG_ENTRY,
            self::PLAN,
            self::CACHED_RESPONSE,
            self::GATE_RESULT,
            self::STACK_MANIFEST,
        ];
    }

    /**
     * Parse `tessera.event/v1` into ['event', 1]. Returns null on malformed input.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parse(string $schema): ?array
    {
        if (! str_starts_with($schema, 'tessera.')) {
            return null;
        }

        $rest = substr($schema, strlen('tessera.'));
        $parts = explode('/v', $rest);

        if (count($parts) !== 2 || $parts[0] === '' || ! ctype_digit($parts[1])) {
            return null;
        }

        return [$parts[0], (int) $parts[1]];
    }

    private function __construct() {}
}
