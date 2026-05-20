<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Cli\ProjectDirectoryName;
use Tessera\Installer\NewCommand;

final class ProjectDirectoryNameTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function validNames(): array
    {
        return [
            'kebab case' => ['my-restaurant'],
            'underscore' => ['project_1'],
            'dot inside' => ['foo.bar'],
            'upper camel' => ['LaravelApp'],
            'digits after first char' => ['project42'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'too long' => [str_repeat('a', 101)],
            'cwd dot' => ['.'],
            'parent dotdot' => ['..'],
            'dot git' => ['.git'],
            'dot tessera' => ['.tessera'],
            'trailing dot' => ['name.'],
            'leading dash' => ['-bad'],
            'space' => ['my project'],
            'slash' => ['my/project'],
            'backslash' => ['my\\project'],
            'reserved con upper' => ['CON'],
            'reserved con lower' => ['con'],
            'reserved con extension mixed' => ['Con.txt'],
            'reserved nul lower' => ['nul'],
            'reserved com zero' => ['COM0'],
            'reserved com one' => ['COM1'],
            'reserved lpt zero' => ['LPT0'],
            'reserved lpt nine' => ['LPT9'],
        ];
    }

    #[Test]
    #[DataProvider('validNames')]
    public function accepts_safe_portable_names(string $name): void
    {
        $this->assertNull(ProjectDirectoryName::validate($name));
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function rejects_dangerous_or_non_portable_names(string $name): void
    {
        $this->assertNotNull(ProjectDirectoryName::validate($name));
    }

    #[Test]
    public function new_command_constructor_rejects_invalid_directory_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid directory name');

        new NewCommand('.');
    }
}
