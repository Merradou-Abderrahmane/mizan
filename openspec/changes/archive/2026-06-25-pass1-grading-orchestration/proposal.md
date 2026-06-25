## Why

`pass1-grading-core` (E2a) shipped and locked the four Pass 1 primitives — grader
client, `RepoDigest`, `Pass1Prompt`, `Pass1ResponseParser` — as standalone,
unit-tested units with the operator-reviewed prompt and parsing rules. They are
not yet wired to a `Run`. This change (E2b) adds the orchestration that turns a
Run into persisted Pass 1 results: `Pass1GradingService::grade(Run)` plus the
`php artisan pass1:grade {run}` command. It is split from E2a deliberately so
the operator's close review of the prompt and parser was isolated, and so the
persistence/idempotency/failure-isolation rules get their own review here.

**No live network call happens in this change's tests** — they use the
`FakeGraderClient` already bound in the test container. The real egress to
opencode/zen is gated to the first *real* `pass1:grade` run, blocked on the
operator confirming `glm-5.2` is on a zero-retention path (see design.md). The
build and the green test suite do not require that confirmation.

## What Changes

- **ADD** `Pass1GradingService::grade(Run)` in `app/Services/Pass1/`: resolve the
  brief's `competences()->technical()` (R2/R3 — only technique competences are
  Pass-1-eligible), each with its pivot `level_id` target; build the `RepoDigest`
  **once** per run from `run.studentRepo.clone_path`; for each competence render
  the blind `Pass1Prompt`, call the `GraderClient`, `parseWithRepair`, and
  persist (in a DB transaction) the per-criterion `evidence` + `drafts`
  (including `ai_reasoning` from the parser's `reasoning` field) and one
  `pass1_competence_results` rollup row (`ai_rollup_status`, `confidence`,
  `probe_questions`, `raw_json`, `level_id`).
- **ADD** idempotent re-grade: re-running `grade(Run)` for a run that already has
  Pass 1 results SHALL replace/update the existing rows cleanly — no duplicate
  `evidence`/`drafts`/`pass1_competence_results` rows, no orphaned partial
  writes. The unique `(run_id, competence_id)` constraint on
  `pass1_competence_results` and the criterion-grain keying of `evidence`/`drafts`
  are the structural basis; the service deletes-then-reinserts within the
  competence's transaction.
- **ADD** failure isolation: a single competence whose grader output is
  `unparseable` (or that throws) SHALL be persisted as a safe `à vérifier`
  `pass1_competence_results` row with `raw_json` kept for audit and empty
  `evidence`/`drafts` for its criteria; all OTHER competences in the run SHALL
  still grade. A single failure never aborts the whole run and never corrupts a
  partial write.
- **ADD** transaction integrity: each competence's persistence is its own DB
  transaction. A mid-run crash (process kill, exception that escapes the
  competence's try/catch) leaves already-committed competences intact and the
  un-finished one with no rows (no half-written competence). The service does
  NOT wrap the whole run in one mega-transaction (that would hold locks across
  N slow LLM calls and risk a partial commit on crash).
- **ADD** `php artisan pass1:grade {run}` console command: resolves the `Run`,
  calls `Pass1GradingService::grade`, sets `run.status` to `pass1_done` (or
  `pass1_partial` if any competence failed), prints a per-competence summary.
  No UI.
- **No schema change, no runner change, no UI, no live egress in tests.** Reuses
  the E1 schema and the E2a primitives verbatim; the column contract from E1
  already prevents the AI writing a verdict — this change does NOT weaken it.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `pass1-grading`: ADD the orchestration requirements — `Pass1GradingService`
  (technical-only scope, one digest per run, one call per competence, idempotent
  re-grade, failure isolation, per-competence transaction) and the `pass1:grade`
  command. The five E2a primitive requirements are untouched.

## Impact

- **New code (`apps/web`)**: `app/Services/Pass1/Pass1GradingService.php`;
  `app/Console/Commands/Pass1GradeCommand.php`; a small per-competence outcome
  DTO (`Pass1CompetenceOutcome`) the service returns for the command's summary.
- **Reuses (no edit)**: `GraderClient`/`ZenGraderClient`/`FakeGraderClient`,
  `RepoDigest`, `Pass1Prompt`, `Pass1ResponseParser`/DTOs, `config/grader.php`,
  and the E1 models (`Run`, `Brief`, `Competence`, `Criterion`, `Evidence`,
  `Draft`, `Pass1CompetenceResult`).
- **Reads**: file contents under `run.studentRepo.clone_path` (via `RepoDigest`,
  no execution). **Writes**: `evidence`, `drafts`, `pass1_competence_results`,
  `runs.status`/`started_at`/`ended_at`.
- **Tests (unit + feature, no network)**: `FakeGraderClient` queued with canned
  per-competence JSON; assert per-criterion `evidence`/`drafts` (with
  `ai_reasoning`) and the `pass1_competence_results` rollup are persisted;
  idempotent re-grade replaces cleanly with no duplicates; one competence
  `unparseable` → that row is safe `à vérifier` with `raw_json` kept and the
  others still grade; mid-competence exception → no half-written rows for that
  competence, committed competences survive; the command persists and reports.
  Grep-verify zero live HTTP in the suite.
- **Hard rules**: R1 (hedged-only persistence via E2a parser + E1 column
  contract; `à vérifier` safe default on failure; `finalVerdict()` untouched),
  R3 (blind, evidence-first, one call per competence at its target level,
  citations verified by the parser), R4 (no identity in digest/prompt; the
  service reads `clone_path` only to build the digest, never into a prompt),
  R5 (boring service — iterate, call, parse, persist). R2 untouched.
- **Security / egress**: **no live egress in this change** (fake grader in
  tests). The real egress is the first real `pass1:grade` run, gated on the
  operator confirming `glm-5.2` zero-retention (precondition in design.md).
  The service itself touches no runner/sandbox boundary.
