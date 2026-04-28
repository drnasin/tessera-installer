<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Plan\RenderContext;

final class RenderContextTest extends TestCase
{
    #[Test]
    public function from_requirements_extracts_known_keys(): void
    {
        $ctx = RenderContext::fromRequirements([
            'description' => 'Restaurant in Split',
            'design_style' => 'warm, earthy',
            'design_colors' => 'olive, cream',
            'languages' => ['hr', 'en'],
            'country' => 'HR',
        ], 'OS: Windows 11', 'Node.js v25.0.0');

        $this->assertSame('Restaurant in Split', $ctx->description);
        $this->assertSame('warm, earthy', $ctx->designStyle);
        $this->assertSame('olive, cream', $ctx->designColors);
        $this->assertSame(['hr', 'en'], $ctx->languages);
        $this->assertSame('HR', $ctx->country);
        $this->assertSame('OS: Windows 11', $ctx->systemContext);
        $this->assertSame('Node.js v25.0.0', $ctx->nodeVersion);
    }

    #[Test]
    public function to_array_includes_langs_helper(): void
    {
        $ctx = new RenderContext(languages: ['hr', 'en', 'de']);

        $arr = $ctx->toArray();

        $this->assertSame('hr, en, de', $arr['langs']);
    }

    #[Test]
    public function hash_is_deterministic_for_same_inputs(): void
    {
        $a = new RenderContext(description: 'X', designStyle: 'modern', languages: ['en']);
        $b = new RenderContext(description: 'X', designStyle: 'modern', languages: ['en']);

        $this->assertSame($a->hash(), $b->hash());
    }

    #[Test]
    public function hash_changes_when_any_field_changes(): void
    {
        $a = new RenderContext(description: 'A');
        $b = new RenderContext(description: 'B');

        $this->assertNotSame($a->hash(), $b->hash());
    }

    #[Test]
    public function hash_is_64_hex_chars(): void
    {
        $ctx = new RenderContext(description: 'anything');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ctx->hash());
    }

    #[Test]
    public function extras_with_reserved_key_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('description');

        new RenderContext(
            description: 'canonical',
            extra: ['description' => 'malicious shadow'],
        );
    }

    #[Test]
    public function multiple_reserved_collisions_are_listed_in_message(): void
    {
        try {
            new RenderContext(extra: ['shop' => 'X', 'payments' => 'Y']);
            $this->fail('Expected InvalidArgumentException not thrown.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('payments', $e->getMessage());
            $this->assertStringContainsString('shop', $e->getMessage());
        }
    }

    #[Test]
    public function non_reserved_extras_keys_pass_through_to_to_array(): void
    {
        $ctx = new RenderContext(extra: ['custom_field' => 'value']);

        $this->assertSame('value', $ctx->toArray()['custom_field']);
    }

    #[Test]
    public function reserved_keys_constant_covers_every_to_array_key(): void
    {
        // Sanity check: if a future contributor adds a field to toArray()
        // without also adding it to RESERVED_KEYS, the guard becomes a lie.
        // This test fails loudly when that drift starts.
        $ctx = new RenderContext;
        $emitted = array_keys($ctx->toArray());

        $missing = array_diff($emitted, RenderContext::RESERVED_KEYS);

        $this->assertSame(
            [],
            array_values($missing),
            'toArray() emits keys missing from RESERVED_KEYS — collision guard would not catch them.',
        );
    }
}
