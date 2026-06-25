## Why

LLM Pass 1 grades **per criterion at a target level**: for a given Run + Brief,
for each **technical** competence, at the level being assessed (Niveau 1/2/3),
it evaluates that level's criteria (blind, evidence-first, one LLM call per
competence), then rolls up to a competence-level draft. The schema after
`criteria-per-level` has criterion-grain criteria/evidence/drafts, but it still
cannot express four things Pass 1 needs: (a) which competences are technical vs
soft-skill/transversal, (b) which level each competence is assessed at for a
brief, (c) the hedged AI vocabulary (`semble…`), and (d) per-competence rollup +
confidence + probe-question storage. This change adds exactly those, schema-only.
The LLM service/prompt/contract is a separate later change (`pass1-wiring`); this
one must land first so the wiring has every column it writes.

## What Changes

- **ADD** `competences.kind` (`technique` | `transversale`, DB DEFAULT
  `transversale`). Pass 1 grades `technique` only; transversal competences are
  operator-validated and never enter a prompt, never get evidence or a draft.
  The safe-exclude default means a competence is never auto-graded unless
  explicitly classified `technique` (mirrors R1's cautious default). *(Default
  flagged for review.)*
- **ADD** `brief_competence` pivot (`brief_id`, `competence_id`, `level_id`,
  `unique(brief_id, competence_id)`). Defines, per brief, which competences are
  assessed and at what target level. `Brief belongsToMany Competence
  withPivot('level_id')`.
- **ADD** `pass1_competence_results` table keyed `(run_id, competence_id)`:
  `level_id`, `ai_rollup_status` (`à vérifier` | `semble valide` |
  `semble non valide`, DEFAULT `à vérifier`), `confidence`, `probe_questions`
  (json), `raw_json`, plus operator finalization (`operator_status`,
  `operator_note`, `finalized_at`) and a `finalVerdict()` guard. This is the
  competence rollup and the operator's finalization point.
- **BREAKING** — **MODIFY** `drafts`: `ai_status` allowed values become
  `à vérifier` | `semble valide` | `semble non valide` (DEFAULT unchanged).
  **REMOVE** `operator_status`, `operator_note`, `finalized_at`, and
  `Draft::finalVerdict()` — operator finalization moves to competence grain on
  `pass1_competence_results`; `drafts` becomes AI-only per-criterion.
  *(Flagged prominently for review — you may prefer to keep per-criterion
  operator fields.)*
- **BREAKING** — **MODIFY** `evidence` to be Pass-1-native: make `check_id`,
  `kind`, `status` **nullable** (Change C established evidence = LLM Pass 1
  findings; runner output stays in `runner_report_json`). The contract's evidence
  item is `{file, line, note}`; `note` maps to the existing `message` column (no
  new column).

## Capabilities

### New Capabilities
<!-- none — extends the existing domain-model capability -->

### Modified Capabilities
- `domain-model`: ADD a Competence-kind requirement, a Brief↔Competence
  assessment-scope (pivot + target level) requirement, and a
  Pass1CompetenceResult requirement (rollup + R1 finalization guard). MODIFY the
  Competence requirement (add `kind`), the Draft requirement (hedged `ai_status`
  enum; AI-only — operator fields removed), the Evidence requirement (Pass-1-native:
  nullable runner columns, `note`→`message`), and the Model-factories requirement
  (new `Pass1CompetenceResult` factory; updated `Draft`/`Competence` factories).

## Impact

- **Migrations**: edit `competences` (add `kind`), `drafts` (hedged enum, drop
  operator fields), `evidence` (nullable `check_id`/`kind`/`status`); add
  `brief_competence` (after briefs+competences+levels) and
  `pass1_competence_results` (after runs+competences+levels). `migrate:fresh`
  (pre-launch, zero data).
- **Models**: `Competence` (`kind`, technical scope), `Brief`
  (`competences()` belongsToMany withPivot), `Draft` (AI-only), `Evidence`
  (unchanged relations), new `Pass1CompetenceResult` (+ `finalVerdict()`), `Run`
  (`hasMany pass1CompetenceResults`).
- **Factories**: new `Pass1CompetenceResultFactory`; update `DraftFactory`
  (drop operator fields, hedged default stays `à vérifier`), `CompetenceFactory`
  (set `kind`).
- **Tests**: extend `DomainSchemaTest` — kind default + technical scope, pivot +
  target level, rollup relations + `finalVerdict` guard + R1 default, hedged
  `ai_status`, AI-only drafts, nullable evidence runner columns + `note` mapping,
  factory coverage.
- **Spec**: MODIFY `openspec/specs/domain-model/spec.md` on archive (+ manual
  `## Purpose` touch-up at closeout).
- **Hard rules**: R1 (model emits only `semble…`/`à vérifier`; operator finalizes
  on the rollup via `finalVerdict()`; `à vérifier` safe default; no-evidence
  criterion defaults `à vérifier`), R3 (Pass 1 blind/evidence-first at criterion
  grain; rollup is not a re-grade; evidence vs probe_flags stay separate), R4 (no
  identity/persona on any new/modified table; identity path stays
  `→ run → student_repo`). R2/R5 untouched. **Sandbox/security: NONE** — `apps/web`
  schema only.
- **Sequencing**: unblocks `pass1-wiring` (E2). Operator reviews E1 as a pure
  schema change before E2 is proposed.
