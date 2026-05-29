<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\EnvPolicy;

/**
 * Per-provider credential isolation for AI subprocess environments (issue #4).
 *
 * These tests assert directly on the array EnvPolicy::apply() produces — the
 * exact array that reaches proc_open() — so they prove the actual isolation
 * boundary, not just which factory was selected. The complementary subprocess
 * round-trip is covered by CommandRunnerTest (php -r reading getenv()).
 *
 * Invariants under test (issue #4):
 *   - claude child:  ONLY Anthropic credentials
 *   - codex child:   ONLY OpenAI credentials
 *   - gemini child:  ONLY Google/Gemini credentials
 *   - detection probe (minimal): NO provider credentials at all
 *   - AI-nesting markers stripped in every case
 *   - unrelated secrets (GITHUB_TOKEN) never reach any AI child
 */
final class EnvPolicyTest extends TestCase
{
    /**
     * Every provider credential + GITHUB_TOKEN we seed before each test, so we
     * can assert presence/absence precisely. Values are deliberately fake.
     *
     * @var array<string, string>
     */
    private const FAKE_SECRETS = [
        'ANTHROPIC_API_KEY' => 'sk-ant-fake',
        'ANTHROPIC_AUTH_TOKEN' => 'ant-token-fake',
        'OPENAI_API_KEY' => 'sk-openai-fake',
        'OPENAI_ORG_ID' => 'org-fake',
        'OPENAI_PROJECT' => 'proj-fake',
        'GOOGLE_API_KEY' => 'goog-fake',
        'GEMINI_API_KEY' => 'gem-fake',
        'GITHUB_TOKEN' => 'ghp-fake-unrelated-secret',
    ];

    /** @var array<string, string|false> Original values to restore in tearDown. */
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
    public function claude_policy_passes_only_anthropic_credentials(): void
    {
        $env = $this->upperKeys(EnvPolicy::forAiTool('claude')->apply());

        $this->assertSame('sk-ant-fake', $env['ANTHROPIC_API_KEY'] ?? null);
        $this->assertSame('ant-token-fake', $env['ANTHROPIC_AUTH_TOKEN'] ?? null);

        $this->assertArrayNotHasKey('OPENAI_API_KEY', $env);
        $this->assertArrayNotHasKey('OPENAI_ORG_ID', $env);
        $this->assertArrayNotHasKey('OPENAI_PROJECT', $env);
        $this->assertArrayNotHasKey('GOOGLE_API_KEY', $env);
        $this->assertArrayNotHasKey('GEMINI_API_KEY', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
    }

    #[Test]
    public function codex_policy_passes_only_openai_credentials(): void
    {
        $env = $this->upperKeys(EnvPolicy::forAiTool('codex')->apply());

        $this->assertSame('sk-openai-fake', $env['OPENAI_API_KEY'] ?? null);
        $this->assertSame('org-fake', $env['OPENAI_ORG_ID'] ?? null);
        $this->assertSame('proj-fake', $env['OPENAI_PROJECT'] ?? null);

        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        $this->assertArrayNotHasKey('ANTHROPIC_AUTH_TOKEN', $env);
        $this->assertArrayNotHasKey('GOOGLE_API_KEY', $env);
        $this->assertArrayNotHasKey('GEMINI_API_KEY', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
    }

    #[Test]
    public function gemini_policy_passes_only_google_and_gemini_credentials(): void
    {
        $env = $this->upperKeys(EnvPolicy::forAiTool('gemini')->apply());

        $this->assertSame('goog-fake', $env['GOOGLE_API_KEY'] ?? null);
        $this->assertSame('gem-fake', $env['GEMINI_API_KEY'] ?? null);

        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        $this->assertArrayNotHasKey('ANTHROPIC_AUTH_TOKEN', $env);
        $this->assertArrayNotHasKey('OPENAI_API_KEY', $env);
        $this->assertArrayNotHasKey('OPENAI_ORG_ID', $env);
        $this->assertArrayNotHasKey('OPENAI_PROJECT', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
    }

    #[Test]
    public function minimal_policy_passes_no_provider_credentials(): void
    {
        $env = $this->upperKeys(EnvPolicy::minimal()->apply());

        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        $this->assertArrayNotHasKey('ANTHROPIC_AUTH_TOKEN', $env);
        $this->assertArrayNotHasKey('OPENAI_API_KEY', $env);
        $this->assertArrayNotHasKey('OPENAI_ORG_ID', $env);
        $this->assertArrayNotHasKey('OPENAI_PROJECT', $env);
        $this->assertArrayNotHasKey('GOOGLE_API_KEY', $env);
        $this->assertArrayNotHasKey('GEMINI_API_KEY', $env);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $env);
    }

    #[Test]
    public function unknown_tool_name_fails_closed_with_no_credentials(): void
    {
        // Fail-closed is the intended failure mode: an unrecognised provider
        // name yields zero credentials rather than leaking another provider's.
        $env = $this->upperKeys(EnvPolicy::forAiTool('totally-unknown')->apply());

        foreach (array_keys(self::FAKE_SECRETS) as $secret) {
            $this->assertArrayNotHasKey($secret, $env, "{$secret} must not leak to an unknown tool");
        }
    }

    #[Test]
    public function all_ai_nesting_markers_are_stripped_in_every_policy(): void
    {
        $markers = [
            'CLAUDECODE',
            'CLAUDE_CODE',
            'CLAUDE_CODE_SSE_PORT',
            'CLAUDE_CODE_ENTRYPOINT',
            'CLAUDECODE_ENTRYPOINT',
            'VIPSHOME',
        ];

        $restore = [];
        foreach ($markers as $marker) {
            $restore[$marker] = getenv($marker);
            putenv($marker.'=should-not-survive');
        }

        try {
            $policies = [
                'claude' => EnvPolicy::forAiTool('claude'),
                'codex' => EnvPolicy::forAiTool('codex'),
                'gemini' => EnvPolicy::forAiTool('gemini'),
                'minimal' => EnvPolicy::minimal(),
                'buildTool' => EnvPolicy::buildTool(),
            ];

            foreach ($policies as $label => $policy) {
                $env = $this->upperKeys($policy->apply());

                foreach ($markers as $marker) {
                    $this->assertArrayNotHasKey(
                        $marker,
                        $env,
                        "{$marker} must be stripped by the {$label} policy",
                    );
                }
            }
        } finally {
            foreach ($restore as $marker => $original) {
                if ($original === false) {
                    putenv($marker);
                } else {
                    putenv($marker.'='.$original);
                }
            }
        }
    }

    #[Test]
    public function path_passes_through_so_subprocesses_can_locate_binaries(): void
    {
        // PATH is the one variable every subprocess must keep — sanity-check it
        // survives the allowlist so the isolation tests above aren't trivially
        // passing on an empty env.
        $env = $this->upperKeys(EnvPolicy::forAiTool('claude')->apply());

        $this->assertArrayHasKey('PATH', $env);
    }

    /**
     * Re-key an env map by uppercase name. apply() preserves the original key
     * case (correct for the child process); tests assert on canonical uppercase
     * names to stay robust across Windows/Unix casing.
     *
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
