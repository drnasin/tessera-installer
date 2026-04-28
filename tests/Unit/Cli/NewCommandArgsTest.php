<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Cli\NewCommandArgs;

final class NewCommandArgsTest extends TestCase
{
    #[Test]
    public function plain_directory_is_extracted(): void
    {
        $args = NewCommandArgs::parse(['my-shop']);

        $this->assertSame('my-shop', $args->directory);
        $this->assertFalse($args->force);
        $this->assertNull($args->forcedStack);
        $this->assertNull($args->requirementsFixturePath);
    }

    #[Test]
    public function force_flag_is_recognised_in_long_and_short_form(): void
    {
        $a = NewCommandArgs::parse(['my-shop', '--force']);
        $b = NewCommandArgs::parse(['my-shop', '-f']);

        $this->assertTrue($a->force);
        $this->assertTrue($b->force);
    }

    #[Test]
    public function stack_flag_space_form(): void
    {
        $args = NewCommandArgs::parse(['my-shop', '--stack', 'static']);

        $this->assertSame('my-shop', $args->directory);
        $this->assertSame('static', $args->forcedStack);
    }

    #[Test]
    public function stack_flag_equals_form(): void
    {
        $args = NewCommandArgs::parse(['my-shop', '--stack=static']);

        $this->assertSame('static', $args->forcedStack);
    }

    #[Test]
    public function requirements_fixture_flag_space_form(): void
    {
        $args = NewCommandArgs::parse(['my-shop', '--requirements-fixture', '/tmp/req.json']);

        $this->assertSame('/tmp/req.json', $args->requirementsFixturePath);
    }

    #[Test]
    public function requirements_fixture_flag_equals_form(): void
    {
        $args = NewCommandArgs::parse(['my-shop', '--requirements-fixture=/tmp/req.json']);

        $this->assertSame('/tmp/req.json', $args->requirementsFixturePath);
    }

    #[Test]
    public function flag_value_is_not_treated_as_directory(): void
    {
        // Directory comes after both flags use space form.
        $args = NewCommandArgs::parse(['--stack', 'static', '--requirements-fixture', '/tmp/r.json', 'my-shop']);

        $this->assertSame('my-shop', $args->directory);
        $this->assertSame('static', $args->forcedStack);
        $this->assertSame('/tmp/r.json', $args->requirementsFixturePath);
    }

    #[Test]
    public function all_flags_combined(): void
    {
        $args = NewCommandArgs::parse([
            'my-shop',
            '--force',
            '--stack=static',
            '--requirements-fixture=/tmp/r.json',
        ]);

        $this->assertSame('my-shop', $args->directory);
        $this->assertTrue($args->force);
        $this->assertSame('static', $args->forcedStack);
        $this->assertSame('/tmp/r.json', $args->requirementsFixturePath);
    }

    #[Test]
    public function empty_args_yield_null_directory(): void
    {
        $args = NewCommandArgs::parse([]);

        $this->assertNull($args->directory);
        $this->assertFalse($args->force);
    }

    #[Test]
    public function trailing_flag_without_value_returns_null(): void
    {
        $args = NewCommandArgs::parse(['my-shop', '--stack']);

        $this->assertSame('my-shop', $args->directory);
        $this->assertNull($args->forcedStack);
    }
}
