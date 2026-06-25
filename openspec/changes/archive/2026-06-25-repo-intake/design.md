## Context

`apps/runner` (merged in `runner-foundation-v0`) emits a fixed-schema JSON
report on stdout. `apps/web` (after `domain-model-migrations`) has the domain
tables: `student_repos`, `runs`, `evidence`, `drafts`, `probe_flags`. But
nothing connects them: the operator has no way to say "clone this repo, run
the structural checks, persist the result." This change adds the missing
bridge — a synchronous service that clones (or accepts a local path), invokes
the runner unchanged, parses the report, persists a Run (with the report blob)
+ linked StudentRepo, and cleans up.

The hard rules in play: R2 (runner dumb and constant — call it, don't modify
it), R3 (Evidence is per-competence for LLM Pass 1 — runner results are input
artifacts, NOT Evidence; they live in `runner_report_json` on the Run and do
NOT touch the `evidence` table), R4 (persona never enters the runner), R5
(boring app).

The sandbox boundary is NOT touched — cloning and running happen on the
operator's Laragon host under the v0 "trusted repos only" constraint.

## Goals / Non-Goals

**Goals:**
- A `RepoIntakeService` in `apps/web/app/Services/` that:
  - Accepts a Git URL or local path, a `Brief` id, and optionally a
    `StudentRepo` id + `operator_persona`.
  - Resolves the input to a local repo path (clone if URL, use-as-is if path).
  - Invokes `apps/runner/bin/runner` as a subprocess (Symfony Process).
  - Parses the JSON report from stdout.
  - Creates/reuses a `StudentRepo`, creates a `Run` (with `runner_report_json`
    blob + status + timestamps), and returns the `Run`. No `Evidence` rows
    are created — runner structural checks are input artifacts, not
    per-competence Per the operator's decision on Option X, they live
    in the blob only; `evidence` stays strictly per-competence as
    `domain-model-migrations` designed it.
  - Deletes the temp clone in a `finally` block (always; local paths untouched).
- Tests covering: URL happy path, local-path happy path, runner-error status,
  clone cleanup on runner failure, and the R2/R4 guarantees (runner called
  as-is, persona never passed to subprocess).

**Non-Goals:**
- No LLM calls, no Drafts, no ProbeFlags (R1 — deterministic only).
- No Livewire components, no HTTP routes, no Blade views (UI is a later change).
- No queue jobs (synchronous now; Horizon/Redis later).
- No `apps/runner` modifications (R2 — call it, don't change it).
- No containerization, no egress gating, no secrets handling (sandbox deferred).
- No shallow-clone depth configuration (fixed `--depth 1` — boring, R5).
- No retry logic, no timeout configuration (the runner has its own time limits
  in the sandbox change; for now we inherit whatever the runner takes).

## Decisions

### D1. Service class, not a queue job or controller
**Rationale:** The user said "synchronous for now, no queue." A plain PHP
service class is the boring choice (R5): testable in isolation, callable from
a future Livewire component or queue job without change. Putting it in
`app/Services/` follows the Laravel convention for domain services.
**Alternatives considered:**
- A queue job: rejected — user explicitly said no queue.
- An Artisan command: rejected — a service is more reusable (future Livewire
  call); an Artisan command can wrap the service later for CLI testing.

### D2. URL vs. local path detection: string starts with `http://`, `https://`, or `git@`
**Rationale:** A Git URL is unambiguously a URL (starts with `http://`,
`https://`, or `git@`). Anything else is treated as a local filesystem path
(absolute or relative). This is boring (R5), deterministic, and matches what
the operator does manually.
**Alternatives considered:**
- A `type` enum parameter: rejected — extra ceremony for no benefit; the input
  string self-describes.
- `file_exists` check: rejected — a URL might not be reachable, and a local
  path might not exist yet; detection should be lexical, not I/O-based.

### D3. Shallow clone to `storage/runner-clones/<uuid>`, delete in `finally`
**Rationale:** `storage/` is Laravel's writable dir (gitignored). A UUID
subdirectory avoids collisions between concurrent runs. `--depth 1` is the
shallowest clone (minimal data, fast). Deletion in `finally` guarantees cleanup
even on runner failure or exception. This is boring (R5) and reversible.
**Alternatives considered:**
- Clone to system temp dir (`sys_get_temp_dir()`): rejected — outside the app
  storage, harder to audit, and the Laragon host may have a different temp
  policy. Keeping it under `storage/` is auditable and containerizable later.
- Full clone: rejected — wasteful; `--depth 1` is sufficient for structural
  checks (the runner only inspects the working tree + git log count).

### D4. Runner invoked via Symfony Process: `php apps/runner/bin/runner <repoPath>`
**Rationale:** Symfony Process is already a Laravel dep. The command is the
runner's documented contract (R2 — call it as-is). The service captures
stdout (the JSON report) and stderr (logs, ignored for now). The PHP binary is
resolved from PATH (or `php` on Windows); the runner path is relative to the
monorepo root (`base_path('apps/runner/bin/runner')`). No env vars are passed
(R2 — the runner's env allowlist is empty).
**Alternatives considered:**
- Include the runner as a Composer dep: rejected — premature; the runner is a
  sibling app in the monorepo. A subprocess call is the R2 contract.
- HTTP call to a runner microservice: rejected — the runner is a CLI, not a
  service; YAGNI.

### D5. NO Evidence rows — runner results live in the blob only (Option X)
**Rationale:** The operator pushed back on making `evidence.competence_id`
nullable to fit runner structural results into the `evidence` table. The
runner's `checks` array is an *input artifact*, not a per-competence Pass 1
finding (R3). Persisting them as `Evidence` rows — even with `competence_id =
null` — would overload the table with two semantic kinds (per-competence LLM
findings vs. per-check runner artifacts) and require downstream code to
filter `WHERE competence_id IS NOT NULL` to get "real" findings. That is a
footgun and a violation of R5 (boring 👎 clever overloading).

**Decision (Option X):** The service creates ZERO `Evidence` rows. The full
runner report is stored as `runner_report_json` on the `Run` (the blob is the
source of truth for runner output, as the `runner-cli` spec pins). The
`evidence` table stays strictly per-competence — `competence_id` non-nullable,
FK RESTRICT — exactly as `domain-model-migrations` designed it. The
`domain-model` spec is NOT modified. No migration touching `evidence` is
added.

A separate `check_results` table is NOT added now (YAGNI — no consumer needs
the runner checks queryable yet). It can be added additively when a real
feature requires it, without touching R3 or the `evidence` table.

**Alternatives considered:**
- Nullable `competence_id` on `evidence` (the original proposal): rejected —
  overloads the table; R3 becomes conditionally-true instead of
  structurally-guaranteed; downstream queries need a null-guard. The operator
  correctly identified this as semantically muddy.
- A `check_results` table now: rejected (YAGNI) — the blob covers the need;
  no consumer queries runner checks in SQL yet. Adding the table later is
  additive and non-R3-touching.

### D6. StudentRepo: create if not provided, reuse if provided by id
**Rationale:** The operator may want to re-run a repo they already registered
(same StudentRepo, new Run). The service accepts an optional `student_repo_id`;
if provided, it reuses that record (and ignores `operator_persona` — the
existing record's persona stands). If not provided, it creates a new
StudentRepo with `name` (derived from the URL/path basename or an explicit
param), `clone_path` (the URL or local path), and `operator_persona` (optional,
defaults to null per R4).
**Alternatives considered:**
- Always create a new StudentRepo: rejected — the operator re-running the same
  repo would get duplicate records.
- Look up by `clone_path`: rejected — the operator may have moved the repo or
  changed the URL; explicit id is clearer and more boring (R5).

### D7. Run status maps directly from runner report top-level status
**Rationale:** The runner report has `status: "pass" | "fail" | "error"`. The
service sets `Run.status` to the same value. `started_at` ← report
`started_at`, `ended_at` ← report `ended_at`. `runner_report_json` ← the full
decoded report as an array (cast to JSON by the model). If the runner exits
non-zero (exit code 1 or 2) but stdout has a valid report, the report's status
field is authoritative (per the runner spec, exit 1 = fail/error with a report
on stdout). If stdout is NOT valid JSON (runner crash), the service creates a
Run with `status: "error"` and stores the raw stdout in `runner_report_json`
as `{"raw_stdout": "..."}` for debugging. No `Evidence` rows are created in
either case (D5).
**Alternatives considered:**
- Map exit code to Run status: rejected — the report's `status` field is the
  designed contract; exit codes are transport-level.

## Sandbox / Security Impact

**Stated explicitly per `openspec/config.yaml` rule ("design: Note the
sandbox/security impact explicitly, even if 'none'").**

This change's security impact: **none — but it widens the attack surface under
the existing v0 constraint, and the constraint MUST hold.**

- **Cloning is NOT containerized.** `git clone --depth 1` runs on the operator's
  Laragon host with normal network egress. A malicious repo URL could, in
  theory, trigger a `post-checkout` hook that executes untrusted code on the
  host. The v0 constraint ("trusted operator-supplied repos only") covers
  this: the operator only feeds it repos they vetted. `git clone --depth 1`
  with hooks disabled would be a hardening step, but that is a sandbox-boundary
  change requiring human review. For now, the constraint stands.
- **Runner runs on the host.** The runner subprocess executes the cloned
  repo's code (composer install, artisan) on the Laragon host, same as
  `runner-foundation-v0`. No new isolation is added here.
- **No secrets, no egress gating, no CPU/mem/time limits** are added by this
  change. The temp clone is under `storage/runner-clones/` (gitignored), and the
  clone is deleted in `finally`. No secrets are mounted or read.
- **Real isolation stays deferred to `change/runner-sandbox`**, which WILL
  touch the sandbox/egress/secrets boundary and therefore REQUIRES human review
  per `openspec/config.yaml`. This change does NOT relax the v0 constraint.

Any future change that allows untrusted repo URLs before `runner-sandbox`
lands is a defect.

## Risks / Trade-offs

- **[Risk] `git clone` runs a `post-checkout` hook from an untrusted repo.**
  → **Mitigation**: v0 "trusted repos only" constraint. Documented in
  `design.md` and inherited from `runner-foundation-v0`. Hardening (clone with
  `--no-checkout` + manual checkout, or containerized clone) is deferred to
  `runner-sandbox`.
- **[Risk] Temp clone not cleaned up if the PHP process is killed (SIGKILL).**
  → **Mitigation**: `finally` block handles normal exits and exceptions. For
  SIGKILL, a stale dir under `storage/runner-clones/` is harmless (gitignored,
  deterministic name) and can be manually cleaned. A cleanup Artisan command is
  a possible follow-up but not required now (R5).
- **[Risk] Runner takes a long time (composer install on a big repo).** The
  synchronous service blocks the caller.
  → **Mitigation**: Acceptable for v0 (single operator, no concurrency). The
  future queue version (Horizon/Redis) will move this off the request thread.
- **[Trade-off] Runner checks are NOT queryable in SQL** (they're in the JSON
  blob). If a future feature needs "show me which checks failed for run X"
  as a SQL query, a `check_results` table is added additively then. For now
  the blob is sufficient and `evidence` stays per-competence (R3 intact).

## Migration Plan

- Additive only: one new service class, one new test class. No migrations, no
  schema changes, no existing behavior changes.
- The `evidence` table is untouched; `domain-model` spec is untouched.
- Landing order: run `php artisan test`. The service is not wired to any
  route or UI yet, so it's inert until a later change calls it.
- Rollback: delete `RepoIntakeService.php` and the test. No migration to
  reverse.

## Open Questions

- OQ1. `git clone` with `--no-checkout` then `git checkout` to avoid hooks? This
  is a sandbox-hardening step and arguably belongs in `runner-sandbox`. Current
  decision: plain `git clone --depth 1` under the v0 trusted-repos constraint.
  Revisit at `runner-sandbox` review.
- OQ2. Should the service return the `Run` model, or a result DTO? Current
  decision: return the `Run` model (boring, R5; the caller can eager-load
  evidence). Veto if you want a DTO.
- OQ3. Should `StudentRepo.name` be auto-derived from the URL/path basename, or
  always passed explicitly? Current decision: if not provided, derive from
  basename (e.g., `repo.git` → `repo`). Boring and good enough for v0.