<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiResponse;

final class AiResponseTest extends TestCase
{
    #[Test]
    public function successful_response_is_not_rate_limited(): void
    {
        $response = new AiResponse(true, 'output');

        $this->assertFalse($response->isRateLimited());
    }

    #[Test]
    public function successful_response_is_not_tool_down(): void
    {
        $response = new AiResponse(true, 'output');

        $this->assertFalse($response->isToolDown());
    }

    #[Test]
    #[DataProvider('rateLimitPatterns')]
    public function detects_rate_limit_from_error_patterns(string $errorText): void
    {
        $response = new AiResponse(false, '', $errorText, 1);

        $this->assertTrue($response->isRateLimited(), "Should detect rate limit in: {$errorText}");
    }

    #[Test]
    #[DataProvider('rateLimitPatterns')]
    public function detects_rate_limit_from_output_patterns(string $pattern): void
    {
        $response = new AiResponse(false, $pattern, '', 1);

        $this->assertTrue($response->isRateLimited(), "Should detect rate limit in output: {$pattern}");
    }

    #[Test]
    public function detects_rate_limit_case_insensitive(): void
    {
        $response = new AiResponse(false, '', 'RATE LIMIT exceeded', 1);

        $this->assertTrue($response->isRateLimited());
    }

    #[Test]
    public function generic_failure_is_not_rate_limited(): void
    {
        $response = new AiResponse(false, '', 'Syntax error in file.php', 1);

        $this->assertFalse($response->isRateLimited());
    }

    #[Test]
    public function exit_code_127_is_tool_down(): void
    {
        $response = new AiResponse(false, '', 'command not found', 127);

        $this->assertTrue($response->isToolDown());
    }

    #[Test]
    #[DataProvider('toolDownPatterns')]
    public function detects_tool_down_from_error_patterns(string $errorText): void
    {
        $response = new AiResponse(false, '', $errorText, 1);

        $this->assertTrue($response->isToolDown(), "Should detect tool down in: {$errorText}");
    }

    #[Test]
    public function generic_failure_is_not_tool_down(): void
    {
        $response = new AiResponse(false, '', 'File not found: model.php', 1);

        $this->assertFalse($response->isToolDown());
    }

    #[Test]
    public function timeout_is_not_tool_down(): void
    {
        $response = new AiResponse(false, '', 'Timeout after 300s', 124);

        $this->assertFalse($response->isToolDown());
    }

    public static function rateLimitPatterns(): array
    {
        return [
            'rate limit' => ['rate limit exceeded'],
            'rate_limit' => ['error: rate_limit_error'],
            'ratelimit' => ['ratelimit reached'],
            'too many requests' => ['Error: too many requests'],
            '429' => ['HTTP 429 response'],
            'overloaded' => ['API is overloaded'],
            'overloaded_error' => ['overloaded_error: try again'],
            'quota exceeded' => ['quota exceeded for this model'],
            'quota_exceeded' => ['error_type: quota_exceeded'],
            'resource_exhausted' => ['RESOURCE_EXHAUSTED: limit'],
            'try again later' => ['Please try again later'],
            'rate limited' => ['You have been rate limited'],
        ];
    }

    public static function toolDownPatterns(): array
    {
        return [
            'connection refused' => ['connection refused on port 443'],
            'network error' => ['network error: no internet'],
            'service unavailable' => ['503 service unavailable'],
            'could not connect' => ['could not connect to API'],
            'failed to start' => ['failed to start ai process'],
        ];
    }
}
