<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

/**
 * Per-build values that get substituted into prompt templates.
 *
 * The compiler hashes templates (with `{{var}}` placeholders intact);
 * the executor renders them through this context immediately before
 * each adapter call. That means the same `plan_hash` can be executed
 * against different RenderContexts and produce different rendered
 * prompts — by design. The event log records both fingerprints
 * (template + rendered) so post-mortem can reconstruct exactly what
 * went out the wire.
 *
 * Sprint 1 has a single flat layer here. Sprint 2 will split this into
 * user/environment/system layers without a schema bump (the recorded
 * `context_hash` is over the full `toArray()`, so any future split is
 * additive).
 */
final readonly class RenderContext
{
    /**
     * Keys that `toArray()` always emits. Anything dropped into `$extra`
     * with one of these names would silently override the canonical
     * field, producing prompt drift no test catches. The constructor
     * refuses to build such a context — see `ensureExtrasDoNotCollide`.
     *
     * Public so tests can assert the full set without duplicating it.
     *
     * @var list<string>
     */
    public const RESERVED_KEYS = [
        'description',
        'designStyle',
        'designColors',
        'languages',
        'langs',
        'nodeVersion',
        'goVersion',
        'flutterVersion',
        'stackVersions',
        'systemContext',
        'memoryContext',
        'userRequirements',
        'country',
        'shop',
        'needsShop',
        'needsRealtime',
        'needsMobile',
        'needsFrontend',
        'payments',
        'paymentProviders',
        'expectedUsers',
        'special',
    ];

    /**
     * @param  list<string>  $languages
     * @param  list<string>  $paymentProviders
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $description = '',
        public string $designStyle = '',
        public string $designColors = '',
        public array $languages = [],
        public string $nodeVersion = '',
        public string $goVersion = '',
        public string $flutterVersion = '',
        public string $stackVersions = '',
        public string $systemContext = '',
        public string $memoryContext = '',
        public string $userRequirements = '',
        public string $country = '',
        public bool $needsShop = false,
        public bool $needsRealtime = false,
        public bool $needsMobile = false,
        public bool $needsFrontend = true,
        public array $paymentProviders = [],
        public string $expectedUsers = 'low',
        public string $special = '',
        public array $extra = [],
    ) {
        self::ensureExtrasDoNotCollide($extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function ensureExtrasDoNotCollide(array $extra): void
    {
        $collisions = array_intersect(array_keys($extra), self::RESERVED_KEYS);

        if ($collisions !== []) {
            sort($collisions);
            throw new \InvalidArgumentException(
                'RenderContext extras collide with reserved render keys: '.implode(', ', $collisions).
                '. Reserved names cannot be overridden via the extras array — set the matching '.
                'constructor argument directly, or pick a different extras key.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $requirements
     */
    public static function fromRequirements(
        array $requirements,
        string $systemContext = '',
        string $nodeVersion = '',
        string $goVersion = '',
        string $stackVersions = '',
        string $memoryContext = '',
        string $flutterVersion = '',
    ): self {
        return new self(
            description: (string) ($requirements['description'] ?? ''),
            designStyle: (string) ($requirements['design_style'] ?? ''),
            designColors: (string) ($requirements['design_colors'] ?? ''),
            languages: array_values(array_map('strval', $requirements['languages'] ?? [])),
            nodeVersion: $nodeVersion,
            goVersion: $goVersion,
            flutterVersion: $flutterVersion,
            stackVersions: $stackVersions,
            systemContext: $systemContext,
            memoryContext: $memoryContext,
            userRequirements: (string) ($requirements['user_requirements'] ?? ''),
            country: (string) ($requirements['country'] ?? ''),
            needsShop: (bool) ($requirements['needs_shop'] ?? false),
            needsRealtime: (bool) ($requirements['needs_realtime'] ?? false),
            needsMobile: (bool) ($requirements['needs_mobile'] ?? false),
            needsFrontend: (bool) ($requirements['needs_frontend'] ?? true),
            paymentProviders: array_values(array_map('strval', $requirements['payment_providers'] ?? [])),
            expectedUsers: (string) ($requirements['expected_users'] ?? 'low'),
            special: (string) ($requirements['special'] ?? ''),
        );
    }

    /**
     * Flat key/value form used by PromptRenderer for substitution AND
     * fed straight into hash() so two contexts with identical content
     * produce identical hashes.
     *
     * Booleans are exposed in two forms: the raw value AND a YES/NO
     * string for prompts that want a human-readable signal (e.g.,
     * `E-COMMERCE: {{shop}}` reads better than `E-COMMERCE: 1`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'designStyle' => $this->designStyle,
            'designColors' => $this->designColors,
            'languages' => $this->languages,
            'langs' => implode(', ', $this->languages),
            'nodeVersion' => $this->nodeVersion,
            'goVersion' => $this->goVersion,
            'flutterVersion' => $this->flutterVersion,
            'stackVersions' => $this->stackVersions,
            'systemContext' => $this->systemContext,
            'memoryContext' => $this->memoryContext,
            'userRequirements' => $this->userRequirements,
            'country' => $this->country,
            'shop' => $this->needsShop ? 'YES' : 'NO',
            'needsShop' => $this->needsShop,
            'needsRealtime' => $this->needsRealtime ? 'YES' : 'NO',
            'needsMobile' => $this->needsMobile ? 'YES' : 'NO',
            'needsFrontend' => $this->needsFrontend ? 'YES' : 'NO',
            'payments' => $this->paymentProviders === [] ? 'none' : implode(', ', $this->paymentProviders),
            'paymentProviders' => $this->paymentProviders,
            'expectedUsers' => $this->expectedUsers,
            'special' => $this->special,
            ...$this->extra,
        ];
    }

    /**
     * Stable hash of the full context. Used in event payloads so a
     * `tessera replay` decision can compare contexts deterministically
     * — same context_hash = same rendered prompt for the same template.
     */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
