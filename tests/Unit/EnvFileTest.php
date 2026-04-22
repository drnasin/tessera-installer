<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\EnvFile;

final class EnvFileTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function safeValues(): array
    {
        return [
            'simple word' => ['simple', 'simple'],
            'number' => ['3306', '3306'],
            'hostname' => ['127.0.0.1', '127.0.0.1'],
            'path-like' => ['database.sqlite', 'database.sqlite'],
            'alphanumeric' => ['abc123_DEF', 'abc123_DEF'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeValues(): array
    {
        return [
            'contains space' => ['hello world', '"hello world"'],
            'contains hash (comment)' => ['p#ss', '"p#ss"'],
            'contains dollar (var interpolation)' => ['p$word', '"p\\$word"'],
            'contains double quote' => ['a"b', '"a\\"b"'],
            'contains backslash' => ['a\\b', '"a\\\\b"'],
            'contains newline' => ["line1\nline2", '"line1\\nline2"'],
            'contains CR' => ["x\ry", '"x\\ry"'],
            'complex password' => ['p@ss w#rd$ "x"', '"p@ss w#rd\\$ \\"x\\""'],
        ];
    }

    #[Test]
    #[DataProvider('safeValues')]
    public function safe_values_are_emitted_bare(string $input, string $expected): void
    {
        $this->assertSame($expected, EnvFile::quote($input));
    }

    #[Test]
    #[DataProvider('unsafeValues')]
    public function unsafe_values_are_quoted_and_escaped(string $input, string $expected): void
    {
        $this->assertSame($expected, EnvFile::quote($input));
    }

    #[Test]
    public function empty_value_is_empty(): void
    {
        $this->assertSame('', EnvFile::quote(''));
    }

    #[Test]
    public function setKey_replaces_existing_assignment(): void
    {
        $env = "APP_ENV=local\nDB_HOST=old\nDB_PORT=3306\n";

        $result = EnvFile::setKey($env, 'DB_HOST', '127.0.0.1');

        $this->assertStringContainsString("DB_HOST=127.0.0.1\n", $result);
        $this->assertStringNotContainsString("DB_HOST=old", $result);
        $this->assertStringContainsString('APP_ENV=local', $result);
        $this->assertStringContainsString('DB_PORT=3306', $result);
    }

    #[Test]
    public function setKey_appends_when_missing(): void
    {
        $env = "APP_ENV=local\n";

        $result = EnvFile::setKey($env, 'DB_HOST', '127.0.0.1');

        $this->assertStringContainsString("DB_HOST=127.0.0.1", $result);
    }

    #[Test]
    public function setKey_quotes_password_with_special_chars(): void
    {
        $env = "DB_PASSWORD=\n";

        $result = EnvFile::setKey($env, 'DB_PASSWORD', 'p@ss w#rd$');

        $this->assertStringContainsString('DB_PASSWORD="p@ss w#rd\\$"', $result);
    }

    #[Test]
    public function setKey_preserves_password_with_backslash(): void
    {
        $result = EnvFile::setKey("DB_PASSWORD=\n", 'DB_PASSWORD', 'a\\b');

        $this->assertStringContainsString('DB_PASSWORD="a\\\\b"', $result);
    }

    #[Test]
    public function setKey_does_not_leak_special_chars_across_keys(): void
    {
        // Regression: password containing newline must not inject extra lines.
        $env = "APP_ENV=local\nDB_PASSWORD=\n";

        $result = EnvFile::setKey($env, 'DB_PASSWORD', "evil\nAPP_DEBUG=true");

        // APP_DEBUG must not appear as its own line.
        $this->assertStringNotContainsString("\nAPP_DEBUG=true\n", $result);
        // It must appear only inside the quoted value.
        $this->assertStringContainsString('DB_PASSWORD="evil\\nAPP_DEBUG=true"', $result);
    }

    #[Test]
    public function removeKey_removes_line(): void
    {
        $env = "APP_ENV=local\nDB_HOST=127.0.0.1\nDB_PORT=3306\n";

        $result = EnvFile::removeKey($env, 'DB_HOST');

        $this->assertStringNotContainsString('DB_HOST', $result);
        $this->assertStringContainsString('APP_ENV=local', $result);
        $this->assertStringContainsString('DB_PORT=3306', $result);
    }

    #[Test]
    public function invalid_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnvFile::setKey('', 'lowercase_bad', 'x');
    }

    #[Test]
    public function key_with_dollar_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnvFile::setKey('', 'KEY$', 'x');
    }
}
