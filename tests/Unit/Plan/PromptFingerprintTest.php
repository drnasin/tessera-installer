<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Plan\PromptFingerprint;

final class PromptFingerprintTest extends TestCase
{
    #[Test]
    public function identical_prompts_produce_identical_hash(): void
    {
        $a = new PromptFingerprint('hello world', '1');
        $b = new PromptFingerprint('hello world', '1');

        $this->assertSame($a->hash, $b->hash);
        $this->assertTrue($a->equals($b));
    }

    #[Test]
    public function different_body_produces_different_hash(): void
    {
        $a = new PromptFingerprint('hello world', '1');
        $b = new PromptFingerprint('hello world!', '1');

        $this->assertNotSame($a->hash, $b->hash);
        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function different_version_produces_different_hash(): void
    {
        $a = new PromptFingerprint('hello world', '1');
        $b = new PromptFingerprint('hello world', '2');

        $this->assertNotSame($a->hash, $b->hash);
    }

    #[Test]
    public function whitespace_change_flips_hash(): void
    {
        $a = new PromptFingerprint("hello\nworld", '1');
        $b = new PromptFingerprint("hello\n world", '1');

        $this->assertNotSame($a->hash, $b->hash);
    }

    #[Test]
    public function hash_is_64_hex_chars(): void
    {
        $f = new PromptFingerprint('anything', '1');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $f->hash);
    }

    #[Test]
    public function short_hash_is_first_12_chars(): void
    {
        $f = new PromptFingerprint('test', '1');

        $this->assertSame(substr($f->hash, 0, 12), $f->shortHash);
        $this->assertSame(12, strlen($f->shortHash));
    }

    #[Test]
    public function matches_uses_constant_time_comparison(): void
    {
        $f = new PromptFingerprint('payload', '1');

        $this->assertTrue($f->matches($f->hash));
        $this->assertFalse($f->matches('not-the-hash'));
        $this->assertFalse($f->matches(''));
    }

    #[Test]
    public function display_combines_short_hash_and_version(): void
    {
        $f = new PromptFingerprint('payload', '3');

        $this->assertSame($f->shortHash.'@v3', $f->display());
    }
}
