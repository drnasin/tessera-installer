<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Adapters;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tessera\Installer\Adapters\AdapterContext;
use Tessera\Installer\Adapters\GeminiAdapter;

final class GeminiAdapterTest extends TestCase
{
    #[Test]
    public function name_is_gemini(): void
    {
        $this->assertSame('gemini', (new GeminiAdapter)->name());
    }

    #[Test]
    public function execute_command_appends_prompt_as_last_argv(): void
    {
        $command = $this->buildCommand(model: null);

        $this->assertSame(['gemini', 'the-prompt'], $command);
    }

    #[Test]
    public function model_flag_appears_before_prompt(): void
    {
        $command = $this->buildCommand(model: 'gemini-2.5-pro');

        $this->assertSame(['gemini', '--model', 'gemini-2.5-pro', 'the-prompt'], $command);
    }

    #[Test]
    public function does_not_use_stdin(): void
    {
        $method = new ReflectionMethod(GeminiAdapter::class, 'usesStdin');

        $this->assertFalse($method->invoke(new GeminiAdapter));
    }

    private function buildCommand(?string $model): array
    {
        $context = new AdapterContext(workingDir: '.', model: $model);
        $method = new ReflectionMethod(GeminiAdapter::class, 'buildExecuteCommand');

        return $method->invoke(new GeminiAdapter, 'the-prompt', $context);
    }
}
