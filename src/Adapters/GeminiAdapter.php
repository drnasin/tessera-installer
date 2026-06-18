<?php

declare(strict_types=1);

namespace Tessera\Installer\Adapters;

/**
 * Adapter for Google's Gemini CLI (`gemini`).
 *
 * Supports `--model` for routing between Flash / Pro tiers. Prompt goes as
 * the last argv argument (the CLI doesn't accept it on stdin in a way
 * that's stable across versions).
 *
 * ## Approval / permission model
 *
 * No permission-bypass flag is passed — Gemini CLI's default approval flow
 * is whatever ships on the user's machine. Tessera does NOT read
 * TESSERA_SAFE_AI for this adapter; the flag only affects Claude
 * (strips --dangerously-skip-permissions).
 *
 * If you set TESSERA_SAFE_AI=1 expecting per-action approval across all
 * tools, be aware that only Claude honours it. A runtime warning is emitted
 * when TESSERA_SAFE_AI is set and gemini is the resolved tool. See the "AI
 * permission mode" section in README.md.
 *
 * At the time of writing the Gemini CLI does not expose a stable
 * non-interactive / auto-approve flag, so unattended builds with Gemini rely
 * on the CLI's own default (which is typically interactive-free for API-key
 * authenticated sessions). This should be re-evaluated if Google adds such a
 * flag in a future release.
 */
final class GeminiAdapter extends AbstractAdapter
{
    public function name(): string
    {
        return 'gemini';
    }

    protected function detectCommand(): array
    {
        return ['gemini', '--version'];
    }

    protected function buildExecuteCommand(string $prompt, AdapterContext $context): array
    {
        $command = ['gemini'];

        if ($context->model !== null) {
            $command[] = '--model';
            $command[] = $context->model;
        }

        $command[] = $prompt;

        return $command;
    }

    protected function usesStdin(): bool
    {
        return false;
    }

    public function supportsModel(?string $model): bool
    {
        return true;
    }
}
