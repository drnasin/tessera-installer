<?php

declare(strict_types=1);

namespace Tessera\Installer\Adapters;

/**
 * Adapter for OpenAI's Codex CLI (`codex`).
 *
 * Codex has its own approval-on-request sandbox. Tessera does NOT pass
 * --dangerously-bypass-approvals-and-sandbox; whether Codex prompts during
 * a run depends on the user's Codex version defaults. TESSERA_SAFE_AI has
 * no effect on Codex today (see disclaimer page on the website).
 *
 * The CLI does not accept a --model flag we control — model selection is
 * configured inside the user's ChatGPT plan / API key. supportsModel()
 * therefore returns true only for the null "default" model.
 */
final class CodexAdapter extends AbstractAdapter
{
    public function name(): string
    {
        return 'codex';
    }

    protected function detectCommand(): array
    {
        return ['codex', '--version'];
    }

    protected function buildExecuteCommand(string $prompt, AdapterContext $context): array
    {
        return ['codex', 'exec', '--skip-git-repo-check', $prompt];
    }

    protected function usesStdin(): bool
    {
        return false;
    }

    public function supportsModel(?string $model): bool
    {
        return $model === null;
    }

    protected function buildChildEnv(): array
    {
        $env = parent::buildChildEnv();

        foreach (['ANTHROPIC_API_KEY', 'GOOGLE_API_KEY', 'GEMINI_API_KEY'] as $other) {
            unset($env[$other]);
        }

        return $env;
    }
}
