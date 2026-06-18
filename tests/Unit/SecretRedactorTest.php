<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\SecretRedactor;

/**
 * Credential redaction for subprocess stderr before persistence (issue #53).
 *
 * Invariants under test:
 *   - Known credential patterns are replaced with [REDACTED]
 *   - Surrounding non-secret diagnostic text is preserved verbatim
 *   - Empty string passes through unchanged
 *   - Text with no secrets passes through unchanged
 */
final class SecretRedactorTest extends TestCase
{
    #[Test]
    public function it_redacts_anthropic_api_key_assignment(): void
    {
        $input = 'Error: ANTHROPIC_API_KEY=sk-ant-api03-supersecretvalue123 not authorized';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('sk-ant-api03-supersecretvalue123', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('Error: ANTHROPIC_API_KEY=', $result);
        $this->assertStringContainsString('not authorized', $result);
    }

    #[Test]
    public function it_redacts_openai_api_key_assignment(): void
    {
        $input = 'OPENAI_API_KEY=sk-proj-abcdefghij1234567890 exported';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('sk-proj-abcdefghij1234567890', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('OPENAI_API_KEY=', $result);
        $this->assertStringContainsString('exported', $result);
    }

    #[Test]
    public function it_redacts_google_api_key_assignment(): void
    {
        $input = 'GOOGLE_API_KEY=AIzaSyAbcdefghijklmnopqrstuvwxyz12345 loaded';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('AIzaSyAbcdefghijklmnopqrstuvwxyz12345', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    #[Test]
    public function it_redacts_gemini_api_key_assignment(): void
    {
        $input = 'GEMINI_API_KEY=gemini-key-abc123xyz loaded';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('gemini-key-abc123xyz', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    #[Test]
    public function it_redacts_pgpassword_assignment(): void
    {
        $input = 'PGPASSWORD=s3cr3tpassword connection failed';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('s3cr3tpassword', $result);
        $this->assertStringContainsString('PGPASSWORD=', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('connection failed', $result);
    }

    #[Test]
    public function it_redacts_mysql_pwd_assignment(): void
    {
        $input = 'MYSQL_PWD=mydbpassword123 access denied';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('mydbpassword123', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('access denied', $result);
    }

    #[Test]
    public function it_redacts_sk_prefixed_token(): void
    {
        $input = 'Authentication failed with token sk-abcdefghijklmnopqrstuvwxyz0123456789';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwxyz0123456789', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('Authentication failed with token', $result);
    }

    #[Test]
    public function it_redacts_github_personal_access_token(): void
    {
        $input = 'remote: Invalid credentials ghp_abcdefghijklmnop1234567890 rejected';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('ghp_abcdefghijklmnop1234567890', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('remote: Invalid credentials', $result);
        $this->assertStringContainsString('rejected', $result);
    }

    #[Test]
    public function it_redacts_bearer_token(): void
    {
        $input = 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9 returned 401';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('Authorization: Bearer', $result);
        $this->assertStringContainsString('returned 401', $result);
    }

    #[Test]
    public function it_redacts_basic_auth_credentials_in_url(): void
    {
        $input = 'curl failed: https://admin:supersecret@example.com/api/endpoint (connection refused)';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('admin:supersecret', $result);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringContainsString('https://', $result);
        $this->assertStringContainsString('@example.com/api/endpoint', $result);
        $this->assertStringContainsString('connection refused', $result);
    }

    #[Test]
    public function it_preserves_non_secret_diagnostic_text(): void
    {
        $input = 'PHP Fatal error: Call to undefined method App\\Models\\User::nonExistentMethod() in /app/src/Service.php on line 42';
        $result = SecretRedactor::redact($input);

        $this->assertSame($input, $result);
    }

    #[Test]
    public function it_passes_through_empty_string(): void
    {
        $this->assertSame('', SecretRedactor::redact(''));
    }

    #[Test]
    public function it_handles_multiple_secrets_in_one_string(): void
    {
        $input = 'ANTHROPIC_API_KEY=sk-ant-test123 and sk-proj-anothertoken456 and https://user:pass@host.example.com/path';
        $result = SecretRedactor::redact($input);

        $this->assertStringNotContainsString('sk-ant-test123', $result);
        $this->assertStringNotContainsString('sk-proj-anothertoken456', $result);
        $this->assertStringNotContainsString('user:pass', $result);
        $this->assertSame(3, substr_count($result, '[REDACTED]'));
        $this->assertStringContainsString('ANTHROPIC_API_KEY=', $result);
        $this->assertStringContainsString('@host.example.com/path', $result);
    }

    /**
     * Regression: short strings that look like a keyword prefix (e.g. "sk-ab")
     * must NOT be redacted — minimum 10 chars after the prefix.
     */
    #[Test]
    public function it_does_not_redact_short_sk_strings(): void
    {
        $input = 'task sk-ab done';
        $result = SecretRedactor::redact($input);

        $this->assertSame($input, $result);
    }

    /**
     * Combined assertion matching the issue acceptance criteria exactly:
     * feed stderr with ANTHROPIC_API_KEY, sk-..., and basic-auth URL;
     * assert persisted excerpt is redacted and ordinary text survives.
     */
    #[Test]
    public function it_satisfies_issue_53_acceptance_criteria(): void
    {
        $stderr = implode("\n", [
            'Build failed.',
            'ANTHROPIC_API_KEY=sk-ant-api03-fakekey9999999999999 is invalid',
            'Token sk-proj-faketoken00000000000000000000 rejected by upstream',
            'Cloning from https://deploy:gh_token_secret_xyz@git.example.com/repo.git',
            'Exiting with code 1.',
        ]);

        $result = SecretRedactor::redact($stderr);

        // Secrets must be gone
        $this->assertStringNotContainsString('sk-ant-api03-fakekey9999999999999', $result);
        $this->assertStringNotContainsString('sk-proj-faketoken00000000000000000000', $result);
        $this->assertStringNotContainsString('deploy:gh_token_secret_xyz', $result);

        // Diagnostic context must survive
        $this->assertStringContainsString('Build failed.', $result);
        $this->assertStringContainsString('ANTHROPIC_API_KEY=', $result);
        $this->assertStringContainsString('rejected by upstream', $result);
        $this->assertStringContainsString('@git.example.com/repo.git', $result);
        $this->assertStringContainsString('Exiting with code 1.', $result);
    }
}
