<?php

declare(strict_types=1);

namespace Tessera\Installer\Adapters;

/**
 * Adapter for OpenAI's Codex CLI (`codex`).
 *
 * ## Approval / permission model
 *
 * Codex has its own sandboxed approval model. Tessera does NOT pass any
 * auto-approve flag (e.g. --dangerously-bypass-approvals-and-sandbox) and
 * does NOT read TESSERA_SAFE_AI for this adapter. Whether Codex prompts for
 * approval during a run depends entirely on the user's installed Codex
 * version and its own defaults.
 *
 * If you set TESSERA_SAFE_AI=1 expecting per-action approval across all tools,
 * be aware that flag only affects Claude (strips --dangerously-skip-permissions).
 * A runtime warning is emitted when TESSERA_SAFE_AI is set and codex is the
 * resolved tool. See the "AI permission mode" section in README.md.
 *
 * ## Model selection
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
}
