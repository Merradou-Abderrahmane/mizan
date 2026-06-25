## Why

`apps/runner` emits an evidence-backed JSON report on stdout, and `apps/web`
now has the domain tables (Change B) to persist a Run and its Evidence — but
nothing wires the two together. The operator has no way to say "take this repo,
run the structural checks, and store the result." This change adds the intake
service that bridges the runner's CLI contract to the domain model: it clones
(or accepts a local path), invokes the runner **unchanged** (R2), parses the
JSON report, persists a Run (with the report as `runner_report_json`) +
linked StudentRepo, and cleans up the clone. After this, the operator can kick
off a structural run end-to-end, synchronously, with no UI and no LLM — the
deterministic evidence spine is populated and ready for the LLM Pass 1 change
that comes next.

## What Changes

- Add a `RepoIntakeService` (PHP class in `apps/web/app/Services/`) that:
  - Accepts a Git repo URL **or** a local filesystem path, plus a `Brief`
    id (and optionally an existing `StudentRepo` id).
  - Resolves the input to a local repo path: if a URL is given, shallow-clone
    (`git clone --depth 1`) to a temp directory under `storage/runner-clones/`;
    if a local path is given, use it as-is (no copy, no clone).
  - Invokes `apps/runner/bin/runner <repoPath>` as a subprocess via Symfony
    Process — the runner is called **as-is**, never modified (R2).
  - Parses the runner's JSON report from stdout.
  - Creates a `StudentRepo` record (name + clone_path/URL, operator_persona
    optional via a parameter) if one was not provided; reuses an existing one
    if provided by id.
  - Creates a `Run` linked to the `StudentRepo` and `Brief`; stores the full
    runner JSON blob in `runner_report_json`; sets `status` from the report
    top-level status; sets `started_at`/`ended_at` from the report.
  - Stores the full runner JSON report as `runner_report_json` on the `Run`.
    The service does NOT create `Evidence` rows — the runner's structural
    checks are input artifacts, not per-competence Pass 1 findings (R3). They
    live in the blob on `Run`; `Evidence` stays strictly per-competence as
    Change B designed it (`competence_id` non-nullable, FK RESTRICT). A
    separate `check_results` table is NOT added now (YAGNI — no consumer needs
    the runner checks queryable yet; it can be added additively when a real
    feature requires it).
  - Deletes the temp clone directory in a `finally` block (always, even on
    failure). Local-path inputs are NOT deleted (they are operator-owned).
- Add tests: service happy path (URL → clone → run → Run persisted +
  cleanup), local-path path (no clone), runner-error handling (report status
  `error`), clone cleanup on failure, and the R2/R4 guarantees (runner called
  as-is, persona never passed to subprocess).
- **No** LLM calls, **no** Livewire components, **no** HTTP routes, **no**
  queue jobs, **no** `apps/runner` modifications, **no** `domain-model`
  spec modifications, **no** migration altering `evidence`.

## Capabilities

### New Capabilities
- `repo-intake`: A synchronous service in `apps/web` that takes a Git URL or
  local path, shallow-clones to a temp directory (URL only), invokes the
  existing runner CLI unchanged, parses the JSON report, persists a `Run` with
  `runner_report_json` and linked `StudentRepo`, and deletes the temp clone.
  The service creates NO `Evidence` rows — runner structural checks are input
  artifacts, not per-competence R3 findings. Sandbox-deferred: cloning runs
  under the v0 "trusted operator repos on local host" constraint; no
  containerization.

### Modified Capabilities
<!-- None — `domain-model` is untouched. The runner structural results live in
     the `runner_report_json` blob on `Run`; `evidence` stays strictly
     per-competence as Change B designed it. A `check_results` table is NOT
     added now (YAGNI). -->

## Impact

- **Code**: New files only in `apps/web/`:
  - `app/Services/RepoIntakeService.php` — the intake service.
  - `database/migrations/<timestamp>_make_evidence_competence_id_nullable.php`
    — alters the `evidence` table.
  - `tests/Feature/RepoIntakeServiceTest.php` — service + cleanup + error tests.
- **APIs**: New internal PHP service (`RepoIntakeService::intake(...)`).
  No HTTP API, no Livewire, no queue.
- **Dependencies**: Uses Symfony Process (already in Laravel's deps) for the
  runner subprocess. Uses `git` binary on PATH for shallow clone (already
  required by the runner). No new Composer packages.
- **Hard rules touched**:
  - **R2** (runner dumb and constant): respected — the runner CLI is invoked
    as-is via `php apps/runner/bin/runner <repoPath>`. No `apps/runner` files
    are modified. The service is the *caller*, not a modification of the
    runner.
  - **R3** (two-pass separation): respected — the service does NOT create
    `Evidence` rows. The runner's structural checks are input artifacts, not
    per-competence Per-competence `Evidence` is the LLM Pass 1 output (a later
    change). The runner results live in `runner_report_json` on the `Run`; the
    `evidence` table stays strictly per-competence (`competence_id`
    non-nullable, FK RESTRICT) exactly as Change B designed it. No ProbeFlags
    are created (Pass 2 is a later change).
  - **R4** (persona never in student-facing output, never in Pass 1):
    respected — `operator_persona` is an optional parameter to the service,
    stored on `StudentRepo` (already `$hidden` per Change B). It is never
    passed to the runner (the runner's env allowlist is empty per R2) and
    never written to `Evidence` (which has no persona column per Change B).
  - **R1** (LLM never emits a final verdict): NOT touched — no LLM, no Draft,
    no verdict. This change is purely deterministic.
  - **R5** (boring app): respected — one service class, one subprocess call,
    one parse, one persistence block, one cleanup. No clever heuristics.
- **Sandbox/security boundary**: **Not touched.** Cloning is under the v0
  "trusted operator-supplied repos on local host" constraint inherited from
  `runner-foundation-v0`. The shallow clone is NOT containerized; `git clone`
  runs on the operator's Laragon host with normal network egress. The runner
  subprocess runs the cloned repo's code on the host (same v0 constraint as
  the runner itself). Real isolation (ephemeral container, egress gating, no
  secrets, hard CPU/mem/time limits) stays deferred to `change/runner-sandbox`,
  which requires human review. This change adds NO new egress, NO secrets
  handling, and NO container — it merely automates what the operator already
  does manually (`git clone` then `php bin/runner <path>`).