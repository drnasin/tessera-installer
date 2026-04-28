<?php

declare(strict_types=1);

namespace Tessera\Installer\Adapters;

/**
 * Adapter for Anthropic's Claude Code CLI (`claude`).
 *
 * Notable behaviours:
 *
 *   - Supports `--model` flag for routing between Haiku / Sonnet / Opus.
 *   - Reads the prompt from stdin (avoids argv-length limits and shell issues).
 *   - In default mode launches with `--dangerously-skip-permissions` so the
 *     installer can scaffold non-interactively. Power users opt out by
 *     setting `TESSERA_SAFE_AI=1` in the environment, in which case Claude
 *     pauses for approval on each action and the installer fails loudly
 *     rather than silently hanging.
 *   - Per-tool environment isolation: only ANTHROPIC_* keys are passed
 *     through. OpenAI / Google credentials never reach this child process.
 */
final class ClaudeAdapter extends AbstractAdapter
{
    public function name(): string
    {
        return 'claude';
    }

    protected function detectCommand(): array
    {
        return ['claude', '--version'];
    }

    protected function buildExecuteCommand(string $prompt, AdapterContext $context): array
    {
        $command = ['claude', '-p', '--output-format', 'text', '--verbose'];

        if (! self::safeAiEnabled()) {
            array_splice($command, 2, 0, ['--dangerously-skip-permissions']);
        }

        if ($context->model !== null) {
            array_splice($command, 1, 0, ['--model', $context->model]);
        }

        return $command;
    }

    protected function usesStdin(): bool
    {
        return true;
    }

    public function supportsModel(?string $model): bool
    {
        return true;
    }

    /**
     * Drop credentials of OTHER providers so they can never leak into the
     * Claude child process. Same intent as the legacy AiTool::cleanEnv()
     * shape but narrowed per-provider.
     */
    protected function buildChildEnv(): array
    {
        $env = parent::buildChildEnv();

        foreach (['OPENAI_API_KEY', 'OPENAI_ORG_ID', 'GOOGLE_API_KEY', 'GEMINI_API_KEY'] as $other) {
            unset($env[$other]);
        }

        return $env;
    }

    /**
     * True when TESSERA_SAFE_AI=1 — the installer should NOT pass
     * --dangerously-skip-permissions. Default false so non-interactive
     * scaffolding works without per-action approval prompts.
     *
     * @internal Exposed for adapter tests; not part of the AdapterInterface.
     */
    public static function safeAiEnabled(): bool
    {
        $val = getenv('TESSERA_SAFE_AI');

        return $val !== false && $val !== '' && $val !== '0';
    }
}
