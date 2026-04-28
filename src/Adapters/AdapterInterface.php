<?php

declare(strict_types=1);

namespace Tessera\Installer\Adapters;

use Tessera\Installer\AiResponse;

/**
 * Versioned contract for an AI CLI adapter.
 *
 * Tessera invokes external AI CLIs (Claude Code, Codex CLI, Gemini CLI) as
 * subprocesses. Each is wrapped in an Adapter so the rest of the installer
 * deals with a uniform shape — not ad-hoc switch-by-name logic. New CLIs
 * (Groq, Ollama, etc.) plug in by implementing this interface.
 *
 * Stable across the v1 schema family. Breaking changes go to a new
 * AdapterInterfaceV2 namespace; v1 implementations stay valid.
 */
interface AdapterInterface
{
    /**
     * Stable adapter identifier. Lower-case, ASCII, no spaces.
     * Used as the key in AdapterRegistry and in events.jsonl payloads.
     */
    public function name(): string;

    /**
     * Best-effort version string of the underlying CLI, or null if not detected.
     * Implementations may cache the first detection call.
     */
    public function version(): ?string;

    /**
     * True if the underlying CLI is installed and responds to a version probe.
     * Cheap to call; implementations may cache.
     */
    public function isAvailable(): bool;

    /**
     * Whether the adapter can route the request to the given model id.
     * A null $model means "use the CLI's default model".
     */
    public function supportsModel(?string $model): bool;

    /**
     * Execute a single prompt and return the response.
     *
     * Adapters MUST emit ai.call.start before launch and ai.call.complete /
     * ai.call.rate_limited / ai.call.tool_down on completion when a
     * non-null EventLog is supplied via the context.
     */
    public function execute(string $prompt, AdapterContext $context): AiResponse;

    /**
     * Cost estimate in EUR for a hypothetical call of the given input/output
     * token sizes. Returns null when the adapter does not have pricing data
     * (e.g., Codex via ChatGPT subscription where calls don't bill per-token).
     */
    public function estimateCost(int $estimatedInputTokens, ?int $estimatedOutputTokens = null): ?float;
}
