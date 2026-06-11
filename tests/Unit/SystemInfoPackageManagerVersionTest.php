<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\SystemInfo;

/**
 * Regression coverage for issue #22.
 *
 * `scoop --version` prints a "Current Scoop version:" header line before the
 * actual version, which previously leaked into `tessera doctor` and the
 * `tessera new` banner as "Package manager: Current Scoop version:". The
 * normalizer must skip header-like lines and produce a clean, name-tagged
 * one-liner across all package-manager probes.
 */
final class SystemInfoPackageManagerVersionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function versionOutputs(): array
    {
        return [
            // Real scoop output: header line, then version + commit info.
            'scoop multi-line header is skipped' => [
                'scoop',
                "Current Scoop version:\n2eb84d4d (HEAD -> master) 2024-08-01\n\nmain bucket:\n",
                'scoop 2eb84d4d (HEAD -> master) 2024-08-01',
            ],
            // Simpler scoop shape with a bare semver on the second line.
            'scoop bare version on second line' => [
                'scoop',
                "Current Scoop version:\n0.5.3\n",
                'scoop 0.5.3',
            ],
            // choco already mentions itself (case-insensitively) -> no prefix.
            'choco single line keeps its own name' => [
                'choco',
                "Chocolatey v2.3.0\n",
                'Chocolatey v2.3.0',
            ],
            // winget bare version gets the name prefixed.
            'winget bare version gets prefix' => [
                'winget',
                "v1.8.1911\n",
                'winget v1.8.1911',
            ],
            // brew multi-line: first line carries a digit and the name.
            'brew first line already names itself' => [
                'brew',
                "Homebrew 4.3.10\nHomebrew/homebrew-core (git revision abc; last commit 2024-08-01)\n",
                'Homebrew 4.3.10',
            ],
            // apt prints name + version on the first line.
            'apt first line names itself' => [
                'apt',
                "apt 2.7.14 (amd64)\n",
                'apt 2.7.14 (amd64)',
            ],
            // CRLF line endings (Windows) must split correctly.
            'crlf header is skipped' => [
                'scoop',
                "Current Scoop version:\r\n0.5.3\r\n",
                'scoop 0.5.3',
            ],
            // Leading blank lines are ignored.
            'leading blank lines ignored' => [
                'winget',
                "\n\nv1.8.1911\n",
                'winget v1.8.1911',
            ],
        ];
    }

    #[Test]
    #[DataProvider('versionOutputs')]
    public function it_normalizes_package_manager_version_output(string $name, string $raw, string $expected): void
    {
        $this->assertSame($expected, SystemInfo::normalizePackageManagerVersion($name, $raw));
    }

    #[Test]
    public function it_falls_back_to_first_non_empty_line_when_no_version_token(): void
    {
        // No line contains a digit -> keep the first non-empty line, name-tagged.
        $this->assertSame(
            'pacman some text',
            SystemInfo::normalizePackageManagerVersion('pacman', "\nsome text\nmore text\n"),
        );
    }

    #[Test]
    public function it_returns_bare_name_for_empty_output(): void
    {
        $this->assertSame('scoop', SystemInfo::normalizePackageManagerVersion('scoop', ''));
        $this->assertSame('scoop', SystemInfo::normalizePackageManagerVersion('scoop', "\n\n  \n"));
    }
}
