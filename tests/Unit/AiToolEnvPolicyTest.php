<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\AiTool;
use Tessera\Installer\EnvPolicy;

/**
 * Proves the legacy AiTool path selects the correct EnvPolicy per mode (issue #4):
 *
 *   - execute()         → EnvPolicy::forAiTool($this->name)  (own provider only)
 *   - checkAvailable()  → EnvPolicy::minimal()               (detection, no creds)
 *
 * AiTool::execute() / checkAvailable() compute their proc_open env via these
 * seam methods, so asserting the seam (plus EnvPolicyTest for apply() content)
 * proves the env that actually reaches the child. We seed fake provider secrets
 * and assert on the realised env array, not just the chosen factory, so a bug
 * that selected the right method but mangled the env would still be caught.
 */
final class AiToolEnvPolicyTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    /** @var array<string, string> */
    private const FAKE_SECRETS = [
        'ANTHROPIC_API_KEY' => 'sk-ant-fake',
        'OPENAI_API_KEY' => 'sk-openai-fake',
        'GOOGLE_API_KEY' => 'goog-fake',
        'GEMINI_API_KEY' => 'gem-fake',
        'GITHUB_TOKEN' => 'ghp-fake',
        // Build/shell credentials that must never reach the legacy AiTool child.
        'COMPOSER_AUTH' => '{"github-oauth":{"github.com":"ghp-in-composer-auth"}}',
        'SSH_AUTH_SOCK' => '/tmp/ssh-agent.fake.sock',
    ];

    protected function setUp(): void
    {
        foreach (self::FAKE_SECRETS as $key => $value) {
            $this->originalEnv[$key] = getenv($key);
            putenv($key.'='.$value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $original) {
            if ($original === false) {
                putenv($key);
            } else {
                putenv($key.'='.$original);
            }
        }

        $this->originalEnv = [];
    }

    #[Test]
    public function claude_execute_policy_isolates_to_anthropic(): void
    {
        $env = $this->upperKeys(AiTool::fake('claude')->executeEnvPolicy()->apply());

        $this->assertSame('sk-ant-fake', $env['ANTHROPIC_API_KEY'] ?? null);
        $this->assertArrayNotHasKey('OPENAI_API_KEY', $env);
        $this->assertArrayNotHasKey('GOOGLE_API_KEY', $env);
        $this->assertArrayNotHasKey('GEMINI_API_KEY', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
        $this->assertArrayNotHasKey('COMPOSER_AUTH', $env);
        $this->assertArrayNotHasKey('SSH_AUTH_SOCK', $env);
    }

    #[Test]
    public function codex_execute_policy_isolates_to_openai(): void
    {
        $env = $this->upperKeys(AiTool::fake('codex')->executeEnvPolicy()->apply());

        $this->assertSame('sk-openai-fake', $env['OPENAI_API_KEY'] ?? null);
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        $this->assertArrayNotHasKey('GOOGLE_API_KEY', $env);
        $this->assertArrayNotHasKey('GEMINI_API_KEY', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
    }

    #[Test]
    public function gemini_execute_policy_isolates_to_google_and_gemini(): void
    {
        $env = $this->upperKeys(AiTool::fake('gemini')->executeEnvPolicy()->apply());

        $this->assertSame('goog-fake', $env['GOOGLE_API_KEY'] ?? null);
        $this->assertSame('gem-fake', $env['GEMINI_API_KEY'] ?? null);
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        $this->assertArrayNotHasKey('OPENAI_API_KEY', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
    }

    #[Test]
    public function detection_policy_carries_no_provider_credentials(): void
    {
        $env = $this->upperKeys(AiTool::detectionEnvPolicy()->apply());

        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        $this->assertArrayNotHasKey('OPENAI_API_KEY', $env);
        $this->assertArrayNotHasKey('GOOGLE_API_KEY', $env);
        $this->assertArrayNotHasKey('GEMINI_API_KEY', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
    }

    #[Test]
    public function detection_policy_is_the_minimal_policy(): void
    {
        // Detection is static (runs before a provider is chosen) and must be
        // structurally incapable of selecting a credential-bearing policy.
        $this->assertSame(
            EnvPolicy::minimal()->allowlist(),
            AiTool::detectionEnvPolicy()->allowlist(),
        );
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function upperKeys(array $env): array
    {
        $result = [];
        foreach ($env as $key => $value) {
            $result[strtoupper($key)] = $value;
        }

        return $result;
    }
}
