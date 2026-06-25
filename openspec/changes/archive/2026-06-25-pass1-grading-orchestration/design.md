## Context

E2a (`pass1-grading-core`, merged PR #8) shipped the four Pass 1 primitives,
unit-tested with no live network: `GraderClient`/`ZenGraderClient`/
`FakeGraderClient`, `config/grader.php`, `RepoDigest` (deterministic, byte-capped,
identity-free, `has(file,line)`), `Pass1Prompt` (blind, rule 5 + per-criterion
`reasoning`), `Pass1ResponseParser` (coerce/drop-phantom/empty→`à vérifier`/
`reasoning`/`parseWithRepair`). They are not yet wired to a `Run`.

E1 (`pass1-schema`, merged PR #7) made the domain ready: `competences.kind`
(technique-only scope), `brief_competence` pivot (`level_id` per competence),
`drafts` AI-only hedged per criterion (`ai_status`/`ai_raw_json`/`ai_reasoning`),
`pass1_competence_results` rollup (`unique(run_id, competence_id)`,
`ai_rollup_status`/`confidence`/`probe_questions`/`raw_json`/`level_id` +
operator `finalVerdict()`), `evidence` Pass-1-native.

This change (E2b) adds the orchestration that turns a Run into persisted Pass 1
results, plus the artisan command. It reuses the E1 schema and E2a primitives
verbatim — **no schema change, no runner change, no UI, no live egress in tests.**

The E2a grader output contract (one JSON object per competence call) is the
persistence input:

```json
{
  "competence_id": "string",
  "level": "1 | 2 | 3",
  "criteria": [
    { "criterion_id": "string",
      "evidence": [{ "file": "string", "line": 0, "note": "string" }],
      "assessment_draft": "à vérifier | semble valide | semble non valide",
      "reasoning": "string" }
  ],
  "competence_draft_rollup": "à vérifier | semble valide | semble non valide",
  "confidence": 0.0,
  "probe_questions": ["string"]
}
```

## Goals / Non-Goals

**Goals:**
- `Pass1GradingService::grade(Run)`: technical-only scope (R2/R3), one digest per
  run, one grader call per competence at its pivot target level, persist
  per-criterion `evidence` + `drafts` (with `ai_reasoning`) + one
  `pass1_competence_results` rollup, in a per-competence transaction.
- Idempotent re-grade: re-running `grade(Run)` replaces/updates cleanly — no
  duplicate rows, no orphaned partial writes.
- Failure isolation: one competence's `unparseable`/throwing output → that
  competence is a safe `à vérifier` row with `raw_json` kept; all other
  competences still grade. A single failure never aborts the run or corrupts a
  partial write.
- Transaction integrity: each competence is its own transaction; a mid-run crash
  leaves committed competences intact and the unfinished one with no rows.
- `php artisan pass1:grade {run}` command; sets `run.status`, prints a summary.
- Tests use `FakeGraderClient` — zero live network. The egress gate (glm-5.2
  zero-retention) blocks only the first *real* run, not the build.

**Non-Goals:**
- No schema/runner/UI change. No new prompt text (locked in E2a). No live egress
  in tests. No Pass 2 (next capability). No queue/async — synchronous now
  (Horizon/Redis later). No retry/backoff beyond E2a's one repair retry.
- No partial-grade resumption across processes: a re-grade re-grades *all*
  technical competences (idempotent replace), not "resume from where it stopped".
  That is a later concern if a run has many competences.

## Decisions

**D1 — One digest per run, one call per competence.** Build `RepoDigest` once
from `run.studentRepo.clone_path` (the only place the service reads identity —
it goes into the digest text, never into a prompt). Then iterate
`run.brief->competences()->technical()`; for each, load the criteria at
`pivot.level_id` (`competence->criteria()->where('level_id', $pivot->level_id)`),
render `Pass1Prompt::build($brief, $competence, $level, $criteria, $digest)`,
call `GraderClient::complete($system, $user)`, and `parseWithRepair` with a
`$reAsk` closure that re-calls the client with the repair hint appended (E2a's
decoupled `callable`). One call per competence keeps each prompt small and lets
one competence fail without burning the run.

**D2 — Per-competence transaction; idempotent replace.** Each competence's
persistence is `DB::transaction(function () use (…) { … })` and writes only that
competence's rows. Inside, idempotency is **delete-then-reinsert** scoped to the
competence: delete `evidence`/`drafts` whose `criterion_id ∈ this competence's
criteria` and the `pass1_competence_results` row keyed `(run_id, competence_id)`,
then insert fresh. Rationale: simpler than upsert-by-id across two tables and
guarantees no stale evidence lingers when a re-grade drops a citation. The
`unique(run_id, competence_id)` constraint on the rollup and the
`criterion_id`-grain keying of `evidence`/`drafts` make the scope unambiguous.
The whole run is NOT wrapped in one transaction — that would hold row locks
across N slow LLM calls and risk a partial commit on crash; per-competence
transactions mean a crash leaves committed competences intact and the
in-flight one with no rows (no half-written competence).

**D3 — Failure isolation (R1 safe default).** For each competence, wrap the
call+parse in try/catch. Outcomes:
- Parsed OK → persist evidence/drafts/rollup (D2).
- `Pass1ParsedResult->unparseable === true` → persist **only** the
  `pass1_competence_results` row: `ai_rollup_status = 'à vérifier'`,
  `confidence = null`, `probe_questions = []`, `raw_json = {unparseable: true,
  raw: <text>}`, `level_id = pivot.level_id`. No `evidence`/`drafts` for its
  criteria (or, equivalently, one `drafts` row per criterion at `à vérifier` with
  empty `ai_raw_json` — chosen: **no evidence/drafts rows**, to keep "failure"
  visibly distinct from "graded-empty"; the rollup's `raw_json` is the audit
  trail). The competence's `finalVerdict()` stays null (operator still finalizes).
- `GraderClient` throws (network/timeout after E2a's retry) → same `à vérifier`
  rollup row with `raw_json = {error: <class>: <message>}`; no evidence/drafts.
- Any other `Throwable` → same safe row; the run continues to the next
  competence. The exception is recorded in the outcome DTO and surfaced in the
  command summary, never re-thrown mid-run.
- The run's `status` is `pass1_partial` if any competence failed, `pass1_done`
  if all succeeded.

**D4 — `pass1:grade` command.** `app/Console/Commands/Pass1GradeCommand.php`,
signature `pass1:grade {run}`. Resolves `Run::findOrFail($run)`. Sets
`run->started_at = now()` before grading and `ended_at = now()` after. Calls
`Pass1GradingService::grade($run)`, which returns `list<Pass1CompetenceOutcome>`
(competence label, status `graded`|`failed`, short reason on failure). Prints a
per-competence table to the console (one line each: `competence — graded` or
`competence — failed: <reason>`), plus a final `Run <id>: pass1_done|partial
(N graded, M failed)`. Exits 0 on `pass1_done`, 0 on `pass1_partial` (operator
must see partial results — a non-zero exit would hide them in a cron context),
and 1 only on hard precondition failure (run not found, no technical
competences, digest build threw). No options/flags now (R5).

**D5 — Service return shape.** `grade(Run): array` of `Pass1CompetenceOutcome`
DTOs (`competence_id`, `competence_label`, `level_id`, `status: 'graded'|'failed'`,
`reason: ?string`, `criterion_count: int`). The command maps these to console
lines; a future UI/Livewire component will map them to a status list. The DTO
carries no student identity and no verdict — only the rollup status lives in the
DB, accessed via the model.

**D6 — No weakening of the E1 column contract.** The service does NOT write
`operator_status`, `operator_note`, or `finalized_at` (those stay null — operator
finalizes later), and does NOT write bare `valide`/`non valide` anywhere. It
writes only the AI hedged columns the E2a parser produces. `finalVerdict()` on
the persisted `Pass1CompetenceResult` therefore returns null until the operator
finalizes — R1 holds structurally, not just in app logic.

**D7 — Egress gate (precondition, not enforced in code).** The first *real*
`pass1:grade` run transmits bounded student code to opencode/zen. Before it, the
operator must confirm `glm-5.2` (`GRADER_MODEL`) is on a zero-retention path.
Per opencode/zen docs (fetched 2026-06-25): paid models are zero-retention /
no-training by default; the listed exceptions are free-tier trial models +
OpenAI/Anthropic (30-day); glm-5.2 is NOT in the exceptions. Residuals to
confirm: (a) glm-5.2 is the *paid* zen model, not a free variant; (b) US-only
residency is acceptable; (c) the docs are a representation, not a signed DPA.
This change ships no live call, so it does not itself transmit anything; the
gate bites only at the operator's first real invocation, not at build/test time.
`GRADER_MODEL` is config so a verified model can be pinned.

## Risks / Trade-offs

- **[Delete-then-reinsert is not incrementally resumable]** → accepted (Non-Goal).
  A re-grade re-grades all technical competences. If a future run has many
  competences and a flaky network, add a "skip already-graded, non-failed
  competences" flag — out of scope here (R5: boring now).
- **[Per-competence transaction vs. whole-run consistency]** → a crash mid-run
  leaves some competences graded and others not. This is *desirable*: the
  operator sees which graded and can re-run (idempotent) to fill the rest. A
  whole-run transaction would risk a partial commit on crash and hold locks
  across slow LLM calls.
- **[Failure row has no `evidence`/`drafts` rows]** → a UI that joins
  `evidence`/`drafts` per criterion must treat "missing rows for this
  competence's criteria" as "à vérifier / failed". The rollup's `raw_json`
  (`{unparseable:…}` or `{error:…}`) is the audit signal. Documented in the
  spec scenario so the future UI handles it.
- **[One call per competence = N network calls]** → acceptable for v0
  (few technical competences per brief). Batching multiple competences into one
  call would bloat the prompt and lose per-competence failure isolation.
- **[Re-grade while operator is editing]** → out of scope (single operator, no
  concurrency guard now). The service overwrites AI columns only; it never
  touches `operator_status`/`operator_note`/`finalized_at`, so an operator's
  in-progress finalization on a *different* competence is safe. A re-grade of an
  already-finalized competence overwrites its AI columns but leaves
  `operator_status`/`finalized_at` intact — the operator's verdict persists.
  (If we later want to refuse re-grade of finalized competences, that's a UI
  guard, not a service rule.)

## Sandbox / Security Impact

**None in this change.** No code execution (the digest only *reads* files under
`clone_path`); no runner/sandbox/egress boundary touched; no real outbound
network in tests (`FakeGraderClient` bound in the test container). The service
touches no `apps/runner/` code.

**Precondition recorded for the first real run (D7):** before the operator runs
`pass1:grade` for real, they must confirm `glm-5.2` zero-retention. This change
ships no live call, so it does not itself transmit any student code; the gate
bites only at the first real invocation, not at build/test time. Any future
change that alters the egress path (different provider, different endpoint,
mounted secrets) re-triggers the human-review rule.

## Migration Plan

No DB migration. No config change (E2a's `config/grader.php` is reused). Ship:
`app/Services/Pass1/Pass1GradingService.php`,
`app/Services/Pass1/Pass1CompetenceOutcome.php`,
`app/Console/Commands/Pass1GradeCommand.php`, and feature tests under
`tests/Feature/Pass1/`. Bind nothing new (the test container already binds
`GraderClient → FakeGraderClient` from E2a). Rollback = delete the three files +
tests; no schema to reverse.

## Open Questions

None blocking. (D7's residuals are operator confirmations, not design questions;
they gate the first real run, not the build.)
