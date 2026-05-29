# Changelog

All notable changes to Tessera Installer are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Manifest gate validation now matches the executor. `StackManifestLoader`
  previously accepted the gate types `php_syntax` and `glob`, but
  `GateEvaluator` never implemented them — a custom stack could compile a
  manifest that then failed at execution with "Unknown gate type". The
  unimplemented types are removed from `StackManifestLoader::ALLOWED_GATE_TYPES`
  (leaving `exists_any` and `exists_all`), so an unsupported gate is now caught
  at manifest load instead of mid-build. A parity test (`GateValidationParityTest`)
  enforces that every allowed gate type is executable. No bundled stack used the
  removed types. (#6)

### Changed

- **Breaking for custom stacks only.** A `stacks/*.yaml` manifest that declared
  a `php_syntax` or `glob` gate type now fails to load with a clear "unknown gate
  type" error. Those gate types were never executable, so any such manifest was
  already broken at build time; the failure simply moves earlier. Bundled stacks
  are unaffected. Note `exists_any`/`exists_all` already support `*`/`?`/`[` glob
  patterns inside their `patterns`/`paths` lists.

## [3.11.2] – 2026-04-29

Polymorphic block builder hardening, driven by a real bug surfaced in the
`v3.11.0` Vinarija Split smoke run. The generated admin showed `[object Object]`
in several block fields and silently dropped keys on save — the canonical
"every Filament Repeater + relationship + JSON data column with a type
discriminator" anti-pattern.

Diagnosed live via Livewire-test round-trips on the smoke project, then
validated with the codex-debate-partner agent (round 2 consensus). The fix
is prompt-only: no installer code changed, but the Laravel admin step's
fingerprint flips so the next `tessera new` run sees the new principles.

### Changed

- `stacks/laravel.yaml` admin step (`prompt_version` 1 → 2): added an explicit
  "Polymorphic Block Builder" principle block. Eight non-negotiable rules cover
  per-block-type form-path namespacing, canonical storage shape preserved via
  `mutateRelationshipDataBefore{Fill,Save,Create}Using`, single-source key
  declaration alongside the form schema, fail-loudly-in-dev / drop-and-log-in-
  prod handling for unknown keys, the RichEditor-vs-Textarea state-shape clash
  (TipTap JSON document leaking into a plain text input rendering as
  `[object Object]`), permissive URL validation that accepts relative `/route`
  paths, canonical underscore form for storage discriminators, and a vendor-
  translation-pack check tied to `app.fallback_locale`. Includes a seven-point
  manual smoke-test checklist (create, clone, type-switch, delete-readd,
  reorder, hidden-section staleness, validation scoping).
- `stacks/laravel.yaml` tests step (`prompt_version` 1 → 2): added a
  `PageBlockBuilderTest` requirement (no-op save round-trip preserves every
  seeded block-data key; rendered admin HTML contains zero `[object Object]`
  and zero `curator::` raw keys) and a `CuratorLocaleTest` requirement
  (every supported locale has a published curator vendor pack OR
  `app.fallback_locale` points to a locale that does).

### Why version-bumped instead of patched silently

Both step prompts now have a different `template_fingerprint`. Plans compiled
against `v3.11.1` of the manifest will cache-bust against this commit. Tagging
makes that explicit so anyone resuming a paused project sees the version delta
in the audit trail.

### Migration note

Nothing to do. New Laravel projects generated after this commit will have the
correct polymorphic-builder layout out of the box. Existing projects
(`v3.11.0`-class output and the Vinarija Split smoke run) need the manual fix
documented in this commit's diff: namespace block-type form paths, route
storage ↔ form translation through the relationship-data mutators, rename any
RichEditor + Textarea sharing a key, accept relative URLs in link-field
validators, and either ship reviewed translations or set
`app.fallback_locale` to one that exists.

## [3.11.1] – 2026-04-29

Hot-fix: `v3.11.0` advertised `php: ^8.2` but pulled in `symfony/yaml: ^8.0`,
which itself requires PHP 8.4+. The constraint was unsatisfiable on PHP 8.2
and 8.3 — `composer install` failed for anyone on those versions, including
two-thirds of the CI matrix.

Resolution: bump the runtime PHP requirement to **`^8.4`** to match what
`symfony/yaml` already silently demanded. `v3.11.0` was effectively a
PHP-8.4-only release; this version makes that honest.

### Changed

- `composer.json`: `php` constraint `^8.2` → `^8.4`.
- CI matrix: dropped PHP 8.2 and 8.3 jobs (3 OS × 2 PHP versions instead of
  3 × 4). Smoke job uses PHP 8.4.
- `bin/tessera --help` and `tessera doctor` messaging: PHP 8.2+ → PHP 8.4+.
- `LaravelStack::preflight` version_compare check raised to 8.4.0.
- README badge + testing line: PHP 8.4–8.5 (and updated test counts to the
  v3.11.0 numbers that should have been there to begin with: 406 tests / 801
  assertions).

### Migration note

If you installed `v3.11.0` on PHP 8.2 or 8.3, `composer install` failed and
nothing happened — there is nothing to undo. Upgrade your PHP to 8.4+ and
`composer global update tessera/installer` will pick up `v3.11.1`.

## [3.11.0] – 2026-04-29

The Sprint 1 architecture re-platform: every stack now runs through a versioned YAML
manifest, every AI call is fingerprinted and logged, and timeouts release control on
every supported OS. Public CLI surface stays compatible — `tessera new my-thing` works
exactly as before — but everything underneath has been rebuilt around an inspectable
plan.

**Release-validated** by two real-AI smoke runs: a 9m 39s static site (Pekarnica
Ognjište) and a 5-resume-cycle Laravel + Filament + e-commerce build (Vinarija Split).
Both projects ship with full event traces, hash-anchored plans, and gate-verified
outputs. See lessons learned below.

### Lessons learned from Laravel smoke (drove the late Sprint 1 fixes)

The wine-shop run uncovered three failure modes that the original engine couldn't
handle. All three are now covered by tests and patterns documented for Sprint 2.

- **Gate-pass overrides non-zero exit.** Twice the AI finished writing every file the
  step's hard gate required — but the subprocess returned non-zero (timeout once,
  rate-limit-mid-stream once) and the step failed. Without an override, resume looped
  forever. `PlanExecutor` now treats a non-success exit + every declared hard gate
  passed as success-with-warning, with the exit code recorded in the event payload.
  Two regression tests cover both flavours. Sprint 2's typed `failureReason` enum
  will replace this string heuristic.
- **Enrichment steps must be skippable on free tier.** The `content` step (Sonnet,
  no gates, not skippable) hit the rate cap at 3.7 seconds and halted a 25-minute
  build that already had a working scaffold. Realigned the Laravel manifest with
  the rest of the stacks (Static / Go / Node / Flutter all mark enrichment steps
  skippable). Going forward, every enrichment step that hits a free-tier rate cap
  should declare `skippable: true`.
- **Complex Laravel scaffold needs 60-min budget, not 40.** Initial budget of 2400s
  was too tight for Opus + e-commerce + 12 packages. Bumped `core_models`, `theme`,
  `admin` to 3600s. Real wall-time on the smoke run: scaffold ~30 min for resume
  attempt, theme 7m 24s, admin 15m 30s.

The smoke also surfaced a positive: the `runAndFixTests` post-yaml hook caught a
PHPUnit 12 / Laravel test runner incompatibility (`--no-interaction` flag mismatch)
and the AI **wrote a bash wrapper script (`run-tests.sh`) to work around it** without
human prompting. Self-healing tests work as designed.

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
