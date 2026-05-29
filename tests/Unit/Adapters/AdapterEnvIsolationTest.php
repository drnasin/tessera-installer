<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Adapters;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Adapters\AdapterInterface;
use Tessera\Installer\Adapters\ClaudeAdapter;
use Tessera\Installer\Adapters\CodexAdapter;
use Tessera\Installer\Adapters\GeminiAdapter;
use Tessera\Installer\EnvPolicy;

/**
 * Proves the newer AdapterRegistry → AbstractAdapter execute path is isolated
 * per-provider (issue #4), matching the legacy AiTool path.
 *
 * AbstractAdapter::execute() builds its child env as
 *   EnvPolicy::forAiTool($this->name())->apply()
 * and probeVersion() uses EnvPolicy::minimal()->apply(). These tests seed fake
 * provider secrets + GITHUB_TOKEN and assert on the env each adapter's name
 * resolves to — the exact array reaching proc_open — closing the
 * "both paths isolated" invariant the issue requires.
 *
 * Before the fix the adapters used a denylist that passed GITHUB_TOKEN and any
 * unrelated secret straight through; that regression is what these assertions
 * guard against.
 */
final class AdapterEnvIsolationTest extends TestCase
{
    /** @var array<string, string> */
    private const FAKE_SECRETS = [
        'ANTHROPIC_API_KEY' => 'sk-ant-fake',
        'OPENAI_API_KEY' => 'sk-openai-fake',
        'OPENAI_ORG_ID' => 'org-fake',
        'GOOGLE_API_KEY' => 'goog-fake',
        'GEMINI_API_KEY' => 'gem-fake',
        'GITHUB_TOKEN' => 'ghp-fake-unrelated',
    ];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

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
    public function claude_adapter_execute_env_isolates_to_anthropic(): void
    {
        $env = $this->executeEnvFor(new ClaudeAdapter);

        $this->assertSame('sk-ant-fake', $env['ANTHROPIC_API_KEY'] ?? null);
        $this->assertArrayNotHasKey('OPENAI_API_KEY', $env);
        $this->assertArrayNotHasKey('GOOGLE_API_KEY', $env);
        $this->assertArrayNotHasKey('GEMINI_API_KEY', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
    }

    #[Test]
    public function codex_adapter_execute_env_isolates_to_openai(): void
    {
        $env = $this->executeEnvFor(new CodexAdapter);

        $this->assertSame('sk-openai-fake', $env['OPENAI_API_KEY'] ?? null);
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        $this->assertArrayNotHasKey('GOOGLE_API_KEY', $env);
        $this->assertArrayNotHasKey('GEMINI_API_KEY', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
    }

    #[Test]
    public function gemini_adapter_execute_env_isolates_to_google_and_gemini(): void
    {
        $env = $this->executeEnvFor(new GeminiAdapter);

        $this->assertSame('goog-fake', $env['GOOGLE_API_KEY'] ?? null);
        $this->assertSame('gem-fake', $env['GEMINI_API_KEY'] ?? null);
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        $this->assertArrayNotHasKey('OPENAI_API_KEY', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
    }

    #[Test]
    public function adapter_detection_probe_carries_no_provider_credentials(): void
    {
        // probeVersion() uses EnvPolicy::minimal()->apply() — assert that env
        // carries none of the provider secrets nor GITHUB_TOKEN.
        $env = $this->upperKeys(EnvPolicy::minimal()->apply());

        foreach (array_keys(self::FAKE_SECRETS) as $secret) {
            $this->assertArrayNotHasKey($secret, $env);
        }
    }

    /**
     * The exact env AbstractAdapter::execute() computes for this adapter:
     * EnvPolicy::forAiTool($adapter->name())->apply().
     *
     * @return array<string, string>
     */
    private function executeEnvFor(AdapterInterface $adapter): array
    {
        return $this->upperKeys(EnvPolicy::forAiTool($adapter->name())->apply());
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
