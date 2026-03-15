<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\NewCommand;

/**
 * Tests for NewCommand::extractJson() — private method tested via Reflection.
 * This is the AI output parser that extracts structured JSON from free-form text.
 */
final class NewCommandJsonTest extends TestCase
{
    private function extractJson(string $text): ?array
    {
        $command = new \ReflectionClass(NewCommand::class);
        $method = $command->getMethod('extractJson');
        $instance = $command->newInstanceWithoutConstructor();

        return $method->invoke($instance, $text);
    }

    #[Test]
    public function extracts_pure_json(): void
    {
        $result = $this->extractJson('{"stack":"laravel","reason":"CMS project"}');

        $this->assertNotNull($result);
        $this->assertSame('laravel', $result['stack']);
    }

    #[Test]
    public function extracts_json_wrapped_in_text(): void
    {
        $text = 'Here is my analysis. Based on the requirements: {"stack":"laravel","reason":"needs CMS"} That is my recommendation.';

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        $this->assertSame('laravel', $result['stack']);
    }

    #[Test]
    public function extracts_json_with_nested_objects(): void
    {
        $text = 'Result: {"stack":"node","config":{"runtime":"next"}}';

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        $this->assertSame('node', $result['stack']);
    }

    #[Test]
    public function returns_null_for_no_json(): void
    {
        $result = $this->extractJson('This is just plain text with no JSON at all.');

        $this->assertNull($result);
    }

    #[Test]
    public function returns_null_for_invalid_json(): void
    {
        $result = $this->extractJson('{stack: laravel, reason: missing quotes}');

        $this->assertNull($result);
    }

    #[Test]
    public function handles_json_with_markdown_wrapper(): void
    {
        $text = "```json\n{\"stack\":\"go\",\"reason\":\"high performance\"}\n```";

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        $this->assertSame('go', $result['stack']);
    }

    #[Test]
    public function handles_empty_string(): void
    {
        $this->assertNull($this->extractJson(''));
    }

    #[Test]
    public function handles_json_with_arrays(): void
    {
        $text = '{"languages":["hr","en"],"needs_shop":true}';

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        $this->assertSame(['hr', 'en'], $result['languages']);
        $this->assertTrue($result['needs_shop']);
    }
}
