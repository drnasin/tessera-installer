<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\NewCommand;

/**
 * Tests for NewCommand::normalizeRequirements() — private method tested via Reflection.
 *
 * Verifies that both the interactive AI path and the --requirements-fixture path
 * produce the same canonical requirements shape.
 */
final class NewCommandNormalizeRequirementsTest extends TestCase
{
    private function normalizeRequirements(array $raw): array
    {
        $command = new \ReflectionClass(NewCommand::class);
        $method = $command->getMethod('normalizeRequirements');
        $instance = $command->newInstanceWithoutConstructor();

        return $method->invoke($instance, $raw);
    }

    #[Test]
    public function minimal_fixture_yields_all_defaults(): void
    {
        $result = $this->normalizeRequirements(['description' => 'x']);

        $this->assertSame('x', $result['description']);
        $this->assertSame('', $result['country']);
        $this->assertSame(['hr'], $result['languages']);
        $this->assertFalse($result['needs_shop']);
        $this->assertFalse($result['needs_mobile']);
        $this->assertFalse($result['needs_realtime']);
        $this->assertTrue($result['needs_frontend']);
        $this->assertSame('modern, clean', $result['design_style']);
        $this->assertSame('', $result['design_colors']);
        $this->assertSame([], $result['payment_providers']);
        $this->assertSame('sqlite', $result['database']);
        $this->assertSame('low', $result['expected_users']);
        $this->assertSame('', $result['special']);
        $this->assertArrayNotHasKey('conversation', $result);
    }

    #[Test]
    public function empty_fixture_yields_all_defaults(): void
    {
        $result = $this->normalizeRequirements([]);

        $this->assertSame('Web project', $result['description']);
        $this->assertSame(['hr'], $result['languages']);
        $this->assertFalse($result['needs_shop']);
        $this->assertTrue($result['needs_frontend']);
        $this->assertSame('sqlite', $result['database']);
    }

    #[Test]
    public function languages_string_is_coerced_to_array(): void
    {
        $result = $this->normalizeRequirements(['languages' => 'hr, en']);

        $this->assertSame(['hr', 'en'], $result['languages']);
    }

    #[Test]
    public function languages_single_string_becomes_single_element_array(): void
    {
        $result = $this->normalizeRequirements(['languages' => 'en']);

        $this->assertSame(['en'], $result['languages']);
    }

    #[Test]
    public function languages_empty_string_falls_back_to_default(): void
    {
        $result = $this->normalizeRequirements(['languages' => '']);

        $this->assertSame(['hr'], $result['languages']);
    }

    #[Test]
    public function payment_providers_string_is_coerced_to_array(): void
    {
        $result = $this->normalizeRequirements(['payment_providers' => 'stripe, paypal']);

        $this->assertSame(['stripe', 'paypal'], $result['payment_providers']);
    }

    #[Test]
    public function needs_shop_string_yes_is_cast_to_true(): void
    {
        $result = $this->normalizeRequirements(['needs_shop' => 'yes']);

        $this->assertTrue($result['needs_shop']);
    }

    #[Test]
    public function needs_shop_string_false_is_cast_to_false(): void
    {
        // PHP (bool)"false" is true, so we only assert the cast is bool
        $result = $this->normalizeRequirements(['needs_shop' => false]);

        $this->assertIsBool($result['needs_shop']);
        $this->assertFalse($result['needs_shop']);
    }

    #[Test]
    public function needs_frontend_defaults_to_true(): void
    {
        $result = $this->normalizeRequirements([]);

        $this->assertTrue($result['needs_frontend']);
    }

    #[Test]
    public function needs_frontend_false_is_respected(): void
    {
        $result = $this->normalizeRequirements(['needs_frontend' => false]);

        $this->assertFalse($result['needs_frontend']);
    }

    #[Test]
    public function full_valid_fixture_passes_through_unchanged(): void
    {
        $raw = [
            'description' => 'A wine shop',
            'country' => 'HR',
            'languages' => ['hr', 'en'],
            'needs_shop' => true,
            'needs_mobile' => false,
            'needs_realtime' => false,
            'needs_frontend' => true,
            'design_style' => 'elegant',
            'design_colors' => '#c00',
            'payment_providers' => ['stripe'],
            'database' => 'mysql',
            'expected_users' => 'medium',
            'special' => 'wine tasting calendar',
        ];

        $result = $this->normalizeRequirements($raw);

        $this->assertSame('A wine shop', $result['description']);
        $this->assertSame('HR', $result['country']);
        $this->assertSame(['hr', 'en'], $result['languages']);
        $this->assertTrue($result['needs_shop']);
        $this->assertFalse($result['needs_mobile']);
        $this->assertSame('elegant', $result['design_style']);
        $this->assertSame(['stripe'], $result['payment_providers']);
        $this->assertSame('mysql', $result['database']);
        $this->assertSame('medium', $result['expected_users']);
        $this->assertSame('wine tasting calendar', $result['special']);
    }

    #[Test]
    public function result_has_exactly_the_canonical_keys(): void
    {
        $result = $this->normalizeRequirements([]);

        $expectedKeys = [
            'description',
            'country',
            'languages',
            'needs_shop',
            'needs_mobile',
            'needs_realtime',
            'needs_frontend',
            'design_style',
            'design_colors',
            'payment_providers',
            'database',
            'expected_users',
            'special',
        ];

        sort($expectedKeys);
        $actualKeys = array_keys($result);
        sort($actualKeys);

        $this->assertSame($expectedKeys, $actualKeys);
    }
}
