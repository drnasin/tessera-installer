<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Adapters;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tessera\Installer\Adapters\AdapterContext;
use Tessera\Installer\Adapters\CodexAdapter;

final class CodexAdapterTest extends TestCase
{
    #[Test]
    public function name_is_codex(): void
    {
        $this->assertSame('codex', (new CodexAdapter)->name());
    }

    #[Test]
    public function execute_command_uses_exec_subcommand_and_skip_git_check(): void
    {
        $command = $this->buildCommand();

        $this->assertSame(['codex', 'exec', '--skip-git-repo-check', 'the-prompt'], $command);
    }

    #[Test]
    public function does_not_use_stdin(): void
    {
        $method = new ReflectionMethod(CodexAdapter::class, 'usesStdin');

        $this->assertFalse($method->invoke(new CodexAdapter));
    }

    #[Test]
    public function supports_only_default_model(): void
    {
        $adapter = new CodexAdapter;

        $this->assertTrue($adapter->supportsModel(null));
        $this->assertFalse($adapter->supportsModel('any-string'));
        $this->assertFalse($adapter->supportsModel('gpt-4'));
    }

    private function buildCommand(): array
    {
        $context = new AdapterContext(workingDir: '.');
        $method = new ReflectionMethod(CodexAdapter::class, 'buildExecuteCommand');

        return $method->invoke(new CodexAdapter, 'the-prompt', $context);
    }
}
