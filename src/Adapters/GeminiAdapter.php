<?php

declare(strict_types=1);

namespace Tessera\Installer\Adapters;

/**
 * Adapter for Google's Gemini CLI (`gemini`).
 *
 * Supports `--model` for routing between Flash / Pro tiers. Prompt goes as
 * the last argv argument (the CLI doesn't accept it on stdin in a way
 * that's stable across versions). No permission-bypass flag is passed —
 * Gemini's CLI default approval flow is whatever ships on the user's
 * machine. TESSERA_SAFE_AI has no effect on Gemini today.
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
