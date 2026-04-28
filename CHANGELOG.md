# Changelog

All notable changes to Tessera Installer are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The Sprint 1 architecture re-platform: every stack now runs through a versioned YAML
manifest, every AI call is fingerprinted and logged, and timeouts release control on
every supported OS. Public CLI surface stays compatible — `tessera new my-thing` works
exactly as before — but everything underneath has been rebuilt around an inspectable
plan.

### Added

- **YAML stack manifests** (`stacks/<name>.yaml`) for all five built-in stacks: Static,
  Go, Node, Laravel, Flutter. Each manifest declares steps with `complexity`,
  `prompt`, `prompt_version`, `adapter_hint`, `model_hint`, `dependencies`, `gates`,
  `skippable`, and `timeout`. Strict allowlist on top-level and step keys — unknown
  keys are rejected at load time rather than silently ignored.
- **`tessera plan` CLI** with three subcommands:
  - `tessera plan compile <yaml> [-o <out.json>]` — compiles a manifest into a
    hash-anchored `plan.json` artifact (`tessera.plan/v1` schema).
  - `tessera plan show [<plan.json>]` — pretty-prints a compiled plan in topological
    order with each step's adapter, model, prompt fingerprint, and gates.
  - `tessera plan diff <a.json> <b.json>` — semantic diff between two plans
    (added/removed steps, prompt body changes, dependency edits, hint changes,
    complexity changes). Exits 0 when identical, 2 when different, 1 on usage error.
- **Adapter layer** in `src/Adapters/`: `AdapterInterface`, `AbstractAdapter`,
  concrete `ClaudeAdapter` / `CodexAdapter` / `GeminiAdapter`, and an
  `AdapterRegistry` with a third-party registration hook. Replaces the implicit
  switch in the legacy `AiTool::tools()` and lets new providers (Groq, Ollama, etc.)
  plug in without modifying core.
- **Plan engine** in `src/Plan/`: `PromptFingerprint` (sha256 over template + version),
  `PlanStep`, `CompiledPlan` (with topological sort, cycle detection, and
  `isHashValid()` refusal on tampered files), `PlanCompiler` (atomic write +
  schema-validated read), `PlanExecutor` (sequential dispatch with rendered prompts,
  gate evaluation, skippable handling, and Memory-before-event ordering),
  `PlanDiff`, and `RenderContext` + `PromptRenderer` for `{{var}}` substitution at
  execution time.
- **Schema versioning** in `src/Schema/`: every persistent artifact (state.json,
  events.jsonl entries, plan.json, cached responses, gate results, stack manifests)
  carries a `schema` discriminator of the form `tessera.<artifact>/v<N>`.
  `ArtifactValidator` refuses unknown shapes rather than guessing.
- **Append-only event trace** in `src/Events/`: `EventLog` writes one JSON line per
  event to `.tessera/events.jsonl` under `LOCK_EX`. Every step emits at least
  `step.start` (with template_fingerprint, context_hash, rendered_prompt_hash) and
  `step.complete` / `step.skip` / `step.fail`. AI calls emit `ai.call.start` /
  `ai.call.complete` / `ai.call.rate_limited` / `ai.call.tool_down`. Gates emit
  `gate.pass` / `gate.fail`. Whole-build emits `build.start` / `build.complete` /
  `build.fail` / `build.resume`.
- **Quality gates** (`exists_any`, `exists_all`) with `hard` / `soft` severity. Hard
  failure halts the step; soft failure logs and continues. Path traversal attempts
  are rejected. Closes the regression where an AI claiming "done!" against an empty
  directory used to count as success.
- **Skippable steps** declared in YAML (`skippable: true`). A failed skippable step
  emits `step.skip` and the build continues. Used for enrichment passes (lint,
  polish, SETUP.md) that hit free-tier rate limits often enough that halting the
  whole build on them is the wrong default.
- **`tessera new --stack=<name>`** flag bypasses the AI stack-decision step. Useful
  for dev iteration so you don't burn an Opus call answering a question you already
  know the answer to.
- **`tessera new --requirements-fixture=<path>`** flag loads requirements from a JSON
  file instead of running interactive Q&A. Pairs with `--stack` for fast smoke
  tests and reproducible CI runs.
- **Cross-OS `killProcessTree($pid)`** in `AbstractAdapter`: Windows uses
  `taskkill /F /T /PID`, Unix walks the tree via `pgrep -P` then `posix_kill`.
  Required because `proc_terminate()` alone leaves grandchildren of the AI CLI
  alive, and their open pipe handles make `proc_close()` block until they self-exit.
- **Argv parser** extracted to `Tessera\Installer\Cli\NewCommandArgs` so flag
  parsing has dedicated unit coverage (10 tests, 22 assertions).
- **`symfony/yaml ^8.0`** added as a runtime dependency. Used by
  `StackManifestLoader`.

### Changed

- All five `*Stack::scaffold()` methods now delegate to `YamlStackRunner::run()`
  for the AI portion of the build. Lifecycle (`preflight` / `postSetup` /
  `completionInfo`) and stack-specific shell sequences (Laravel's pre-AI
  `composer create-project` + package install + filament:install + configs +
  structure + db config; Flutter's `flutter create`) stay in PHP — those are too
  tool-specific to push through a generic YAML runner in v1.
- `LaravelStack` shrank from 1677 lines to 1335 (the 366-line inline `aiScaffold()`
  now lives in `stacks/laravel.yaml` as six versioned templates). Static, Go, Node,
  and Flutter stacks each shed roughly 70% of their previous size for the same
  reason. **Net: -1231 lines of PHP, +54.5 KB of versioned YAML templates.**
- `Memory::init()` now writes a `schema` field (`tessera.state/v1`) and a build
  `trace_id`. Older state files load fine — the field is added on the next save.
- `PlanExecutor` evaluates gates after every step's adapter call and emits a
  `gate.pass` / `gate.fail` event regardless of outcome. Memory state is written
  before the matching terminal build event so a SIGINT between writes leaves the
  audit incomplete (acceptable) rather than the resume-state inconsistent (not).
- `AbstractAdapter::runProcess()` no longer reads pipes inside its timeout-check
  loop — PHP's "non-blocking" mode on `proc_open` pipes does not actually
  honour `stream_set_blocking(false)` on Windows, which made the time check
  unreachable while the AI CLI was alive. Pipe reads now happen exactly once,
  after the process is known to have exited or been killed. Live streaming is
  the cost; correct timeouts are the gain.
- README: test counts updated from 131 / 227 to 399 / 782, `tessera plan` CLI
  documented, Laravel stack section updated to reflect the YAML port.
- CONTRIBUTING: directory layout updated, test counts updated.

### Removed

- `src/Stacks/Prompts/LaravelPrompts.php` (462 LoC). Its content lives in
  `stacks/laravel.yaml` as `{{var}}` templates with explicit `prompt_version`.

### Fixed

- Windows process-tree kill bug where a 300 s timeout effectively fired only
  after 633 s because `proc_close()` waited for the Claude CLI's grandchild
  Node process to release pipe handles.
- `StaticStack` heredoc prompts could not be diffed, fingerprinted, or rendered
  with delimiter wrapping for user-supplied data. The YAML port closes that gap
  for every stack.

### Security

- **Prompt injection mitigation**: `PromptRenderer` wraps every untrusted
  `RenderContext` field (description, designStyle, designColors, country,
  userRequirements, etc.) in delimited `<<<USER_DATA name="...">>>...
  <<<END_USER_DATA>>>` blocks before substitution. Trusted fields
  (`systemContext`, `memoryContext`, version strings, `langs`) are inlined raw
  via an explicit `TRUSTED_VARS` allowlist.
- `GateEvaluator` rejects path-traversal patterns (`..`) and refuses to glob
  outside the working directory.
- AI-credential isolation per adapter: `ClaudeAdapter` strips
  `OPENAI_*` / `GOOGLE_*` / `GEMINI_*` env from its child;
  `CodexAdapter` strips `ANTHROPIC_*` / `GOOGLE_*` / `GEMINI_*`;
  `GeminiAdapter` strips `ANTHROPIC_*` / `OPENAI_*`. The `CLAUDECODE` /
  `CLAUDE_CODE_*` AI-nesting markers are scrubbed in every case.

### Test stats

399 tests, 782 assertions, 1 pre-existing skip. Original 222 retained
plus 177 new across `Adapters`, `Schema`, `Events`, `Plan`, `Manifest`,
`Cli`, `Commands`, and feature-level `StackContractTest` /
`StaticYamlStackTest`. CI matrix unchanged: 3 OS × 4 PHP versions.

## [3.10.1] – earlier

See git history.
