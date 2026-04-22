<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\DatabaseIdentifier;

/**
 * DatabaseIdentifier is the last line of defence against shell/SQL injection
 * through DB names. These tests document the exact allowlist.
 */
final class DatabaseIdentifierTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function validNames(): array
    {
        return [
            'simple alpha' => ['tessera'],
            'snake case' => ['my_project'],
            'kebab case' => ['my-project'],
            'alphanumeric' => ['project42'],
            'uppercase' => ['MyDb'],
            'underscore prefix' => ['_internal'],
            'single letter' => ['a'],
            'max length 63 chars' => [str_repeat('x', 63)],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'starts with digit' => ['1db'],
            'starts with hyphen' => ['-db'],
            'contains space' => ['my db'],
            'contains semicolon' => ['db;DROP'],
            'contains quote' => ['db"name'],
            'contains backtick' => ['`db`'],
            'contains backslash' => ['db\\x'],
            'contains dollar' => ['db$(x)'],
            'contains pipe' => ['db|rm'],
            'contains dot' => ['db.table'],
            'contains slash' => ['db/x'],
            'contains null byte' => ["db\0"],
            'newline' => ["db\n"],
            'tab' => ["db\tname"],
            'over 63 chars' => [str_repeat('x', 64)],
            'unicode' => ['projét'],
            'ascii shell escape' => ['$(whoami)'],
        ];
    }

    #[Test]
    #[DataProvider('validNames')]
    public function accepts_valid_names(string $name): void
    {
        $this->assertTrue(DatabaseIdentifier::isValid($name), "Should accept: {$name}");
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function rejects_invalid_names(string $name): void
    {
        $this->assertFalse(
            DatabaseIdentifier::isValid($name),
            'Should reject: '.addslashes($name),
        );
    }

    #[Test]
    public function assertValid_throws_with_descriptive_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid database name/');

        DatabaseIdentifier::assertValid('db; DROP TABLE users', 'database name');
    }

    #[Test]
    public function assertValid_does_not_throw_for_valid(): void
    {
        DatabaseIdentifier::assertValid('good_name');
        $this->assertTrue(true); // no exception
    }
}
