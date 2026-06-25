## Context

After `criteria-per-level` (Change D), the domain model mirrors the référentiel's
competence → level → criteria structure: a `criteria` table holds the evaluable
unit per `(competence, level)`, and `evidence`/`drafts`/`probe_flags` key on
`criterion_id`. The next planned change is the LLM Pass 1 wiring, which grades
**per criterion at a target level**, one LLM call per technical competence,
rolling up to a competence-level draft. The agreed output contract is:

```json
{
  "competence_id": "string",
  "level": "1 | 2 | 3",
  "criteria": [
    { "criterion_id": "string",
      "evidence": [{ "file": "string", "line": 0, "note": "string" }],
      "assessment_draft": "à vérifier | semble valide | semble non valide" }
  ],
  "competence_draft_rollup": "à vérifier | semble valide | semble non valide",
  "confidence": 0.0,
  "probe_questions": ["string"]
}
```

The schema cannot yet store the `level` being assessed per competence, the
technical/transversal distinction, the hedged `semble…` vocabulary, or the
per-competence rollup/confidence/probe_questions. This change (E1) adds those —
schema only. The LLM service, prompt text, and JSON parsing are a separate change
(E2) the operator will review closely on its own.

## Goals / Non-Goals

**Goals:**
- Make the schema able to store everything the Pass 1 contract emits, at the
  right grain (per-criterion evidence + draft; per-competence rollup).
- Structurally exclude transversal competences from Pass 1 (a `kind` flag), so
  the wiring cannot accidentally grade a soft skill.
- Encode the assessed target level per competence per brief (the assessment
  scope), so the wiring knows which criteria to evaluate.
- Keep R1 honest: the model only ever produces `semble…`/`à vérifier`; the
  operator finalizes a `valide`/`non valide` verdict, guarded by `finalVerdict()`.
- One meaning per table (Change C/D discipline): per-criterion vs per-competence
  storage stay distinct; finalization lives at exactly one grain.

**Non-Goals:**
- No LLM client, prompt, API call, JSON parsing, retry, or grading logic — all E2.
- No roll-up *computation* logic (deriving the rollup from criterion drafts) — E2
  writes the rollup the model returns; any app-side recomputation is later.
- No UI / operator finalize screen — later.
- No référentiel seeding of real `kind`/level data — factories only.
- No `apps/runner` / sandbox change.

## Decisions

**D1 — `competences.kind` defaults to `transversale` (safe-exclude).**
A competence is graded by Pass 1 only if explicitly `technique`. A forgotten
classification is therefore *excluded* (operator notices "why isn't X graded?")
rather than wrongly graded (code "demonstrating" a soft skill — worse, and an R-
spirit violation). Mirrors R1's "default to the cautious side." `Competence`
gets a `scopeTechnical()` query scope.
- *Alternative:* default `technique`, or no default (force explicit). Rejected —
  default `technique` makes the dangerous direction the silent one; "no default"
  adds friction with no safety gain over safe-exclude. **Flagged for review.**

**D2 — Target level lives on a `brief_competence` pivot.**
Per the operator decision, different competences can be assessed at different
levels in one project. The pivot `(brief_id, competence_id, level_id)` with
`unique(brief_id, competence_id)` defines both the assessment scope (which
competences this brief covers) and the target level for each. `Brief
belongsToMany Competence withPivot('level_id')`. Pass 1 (E2) iterates the brief's
competences filtered to `kind = 'technique'`, and for each, evaluates the criteria
of `(competence, pivot.level_id)`.
- *Alternative:* one global level per run, or operator-picks-at-launch. Rejected
  by the operator — a brief assessing different competences at different levels is
  the real case.

**D3 — `pass1_competence_results` is the rollup + the finalization point.**
Keyed `(run_id, competence_id)`, `unique`. Holds the AI rollup
(`ai_rollup_status` ∈ `à vérifier`/`semble valide`/`semble non valide`, DEFAULT
`à vérifier`), `confidence` (decimal, nullable), `probe_questions` (json),
`raw_json` (full LLM response for audit), and a snapshot `level_id` (the level
assessed). It also carries the operator finalization: `operator_status`
(`valide`/`non valide`/`à vérifier`, nullable), `operator_note`, `finalized_at`,
and `finalVerdict(): ?string` returning `operator_status` iff `finalized_at` is
non-null — the same R1 guard pattern Change B put on `Draft`. `Run hasMany
pass1CompetenceResults`.

**D4 — Finalization moves to competence grain; `drafts` becomes AI-only.**
With D3 holding the operator verdict at competence grain, the `operator_status` /
`operator_note` / `finalized_at` / `finalVerdict()` that Change B put on the
criterion-grained `drafts` are now redundant. Remove them: `drafts` becomes the
AI's per-criterion hedged assessment only (`ai_status`, `ai_raw_json`,
`ai_reasoning`). This keeps one finalization grain and no dead columns (R5).
- *Alternative:* keep per-criterion operator finalization too (operator can mark
  individual criteria). Possible, but the référentiel verdict is competence-level;
  per-criterion finalization isn't how the instructor works, and two finalization
  grains reintroduce ambiguity. **Flagged prominently for review — this is the
  one decision most worth your push-back.**

**D5 — Hedged vocabulary, split across AI vs operator columns.**
The model's columns (`drafts.ai_status`, `pass1_competence_results.ai_rollup_status`)
use `à vérifier` | `semble valide` | `semble non valide` — the model never asserts
a bare verdict (R1). The operator's column (`pass1_competence_results.operator_status`)
uses `valide` | `non valide` | `à vérifier`. `à vérifier` is the DB default on both
AI columns, so a raw insert is safe. A criterion with no surviving evidence is
left at `à vérifier` (enforced by E2, defaulted by the schema).

**D6 — `evidence` becomes Pass-1-native.**
Change C established that runner output stays in `runner_report_json` and
`evidence` holds LLM Pass 1 findings. The Change B runner-oriented columns
(`check_id`, `kind`, `status`, all NOT NULL with runner-ish enums) don't fit an
LLM citation `{file, line, note}`. Make `check_id`, `kind`, `status` **nullable**
so Pass 1 evidence can omit them; map the contract's `note` to the existing
nullable `message` column (no new column). `criterion_id`, `file_path`,
`line_number`, `excerpt` stay.
- *Alternative:* drop the runner columns outright. Rejected — keeping them
  nullable is lower-churn and leaves the door open without overloading meaning.

## Risks / Trade-offs

- **[D4 removes columns Change B added and tested]** → Pre-launch, zero data; the
  `domain-model` spec is updated via a MODIFIED delta so canonical stays correct.
  Flagged for explicit operator sign-off because it relocates the R1 finalization
  mechanism.
- **[Two `finalVerdict()` guards historically]** → After D4 there is exactly one,
  on `Pass1CompetenceResult`. The R1 guarantee (model never a final verdict) is
  preserved and centralized.
- **[Task count]** → Five schema areas + models + factories + tests approaches the
  ~15-task limit. Kept atomic by grouping migration+model+factory+test per area; if
  it exceeds 15 at task-writing time, split off the `evidence`/`drafts` MODIFYs
  from the three ADDs. Noted, not pre-split.
- **[`kind` safe-exclude surprises the operator]** → Most competences are
  technical, so many need an explicit `kind='technique'` at seed time. Accepted:
  explicit classification is correct for a flag that gates grading. Flagged.

## Sandbox / Security Impact

**None.** `apps/web` schema + models + factories + tests only. No `apps/runner`,
no Docker, no egress, no secrets, no network boundary. The v0 "trusted repos on
local Laragon host" constraint is untouched; sandbox hardening stays deferred to
`change/runner-sandbox` (requires human review).

## Migration Plan

1. Edit `..._100002_create_competences_table.php`: add `kind` (string, default
   `'transversale'`).
2. Edit `..._100006_create_evidence_table.php`: make `check_id`, `kind`, `status`
   nullable.
3. Edit `..._100007_create_drafts_table.php`: keep hedged `ai_status` (DEFAULT
   `'à vérifier'`); drop `operator_status`, `operator_note`, `finalized_at`.
4. Add `..._100009_create_brief_competence_table.php` (after briefs/competences/
   levels).
5. Add `..._100010_create_pass1_competence_results_table.php` (after runs/
   competences/levels).
6. `php artisan migrate:fresh`; then `php artisan test`.
7. Rollback: revert the branch (no production data).
