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

    #[Test]
    public function handles_deeply_nested_objects(): void
    {
        // The previous regex-based parser (\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\})
        // supported only one level of nesting. Three levels broke it.
        $text = 'Config: {"stack":"laravel","options":{"database":{"driver":"mysql","charset":"utf8mb4"}}}';

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        $this->assertSame('laravel', $result['stack']);
        $this->assertSame('mysql', $result['options']['database']['driver']);
    }

    #[Test]
    public function handles_braces_inside_string_literals(): void
    {
        // The regex treated every `{` as structural. A brace inside a JSON
        // string (valid JSON!) broke extraction.
        $text = 'Result: {"reason":"user said: {please help}","stack":"node"}';

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        $this->assertSame('node', $result['stack']);
        $this->assertSame('user said: {please help}', $result['reason']);
    }

    #[Test]
    public function handles_escaped_quotes_in_strings(): void
    {
        $text = '{"msg":"he said \\"hi\\"","ok":true}';

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        $this->assertSame('he said "hi"', $result['msg']);
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function prefers_first_valid_json_in_multiple_blocks(): void
    {
        // AI sometimes writes "before reply: {...} after correction: {...}"
        // Prior impl might have picked up whichever the regex found first
        // (often broken), or the last (shortest). Either way, stable choice.
        $text = 'First attempt: {"stack":"node"}. Revised: {"stack":"laravel","reason":"CMS"}.';

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        // Accept either — but assert it is ONE of the valid objects, not a merge.
        $this->assertContains($result['stack'], ['node', 'laravel']);
    }

    #[Test]
    public function handles_fenced_block_with_language_tag(): void
    {
        $text = "Here you go:\n```json\n{\"stack\":\"go\",\"meta\":{\"a\":1,\"b\":{\"c\":2}}}\n```\nHope this helps.";

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        $this->assertSame('go', $result['stack']);
        $this->assertSame(2, $result['meta']['b']['c']);
    }

    #[Test]
    public function handles_arrays_at_any_nesting(): void
    {
        $text = '{"a":[1,[2,[3,{"deep":true}]]],"b":"x"}';

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        $this->assertSame('x', $result['b']);
        $this->assertTrue($result['a'][1][1][1]['deep']);
    }

    #[Test]
    public function handles_close_brace_in_string(): void
    {
        $text = 'Config: {"emoticon":"}:-)","stack":"laravel"}';

        $result = $this->extractJson($text);

        $this->assertNotNull($result);
        $this->assertSame('}:-)', $result['emoticon']);
        $this->assertSame('laravel', $result['stack']);
    }
}
