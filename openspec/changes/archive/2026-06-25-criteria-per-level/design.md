## Context

Change B (`domain-model-migrations`, archived) created 9 domain tables but
modeled the référentiel at the wrong grain. The real référentiel structure:

- A **Competence** spans three progressive **Levels** (Niveau 1 émerger /
  2 adapter / 3 transposer). Levels are référentiel-global (the same three
  semantic rungs apply across competences) — the existing `levels` table,
  scoped by `referentiel_id`, already models this correctly.
- Each `(Competence, Level)` pair has its own **critères d'évaluation** — the
  actual evaluable units the grader judges.

Change B has no criterion entity. Instead it:
- gives `competences` a single nullable `level_id` ("this competence is at one
  level"), which contradicts "a competence spans all three levels"; and
- keys `evidence`, `drafts`, and `probe_flags` on `competence_id`, so Pass 1
  would emit one verdict per competence.

The next planned change is the LLM Pass 1 wiring. Pass 1 must grade at the
criterion grain (one evidence-backed draft verdict per criterion), with level
and competence attainment as roll-ups. Building Pass 1 against a
competence-grained schema would bake in the wrong unit. The Pass-1 tables are
empty (pre-launch, single operator, zero data), so re-graining now is free.

## Goals / Non-Goals

**Goals:**
- Model the criterion as a first-class entity belonging to a `(Competence, Level)`
  pair, with a `unique(competence_id, level_id, code)` constraint.
- Make `criterion_id` the single grain key for `evidence`, `drafts`, and
  `probe_flags`; competence reachable only via `criterion → competence`.
- Remove the wrong `competences.level_id` relation.
- Keep the schema readable as one coherent set by editing the Change B migration
  files (operator decision), validated by `migrate:fresh`.
- Preserve every R1/R3/R4 guarantee Change B encoded, re-pointed at criterion grain.

**Non-Goals:**
- No LLM, Pass 1, prompt, or grading logic — that is the next change.
- No roll-up/aggregation logic (level/competence attainment from criterion
  verdicts) — computed later when a consumer needs it (YAGNI).
- No seeding of real référentiel criteria content — factories only.
- No data migration (there is no data).
- No `apps/runner` / sandbox change.

## Decisions

**D1 — Criterion = join of (Competence, Level), not a child of one.**
A criterion carries both `competence_id` and `level_id` FKs. Both cascade on
delete (a criterion has no meaning without either parent). `unique(competence_id,
level_id, code)` lets the same code (e.g. `C1`) repeat across levels while staying
unique within a `(competence, level)` cell.
- *Alternative considered:* nest criteria under a per-competence level row
  (each competence owns 3 level rows). Rejected — the three levels are
  référentiel-global semantics; duplicating them per competence denormalizes the
  level vocabulary and complicates cross-competence level reasoning.

**D2 — `criterion_id` is the SINGLE grain key on the Pass-1 tables.**
Replace `competence_id` with `criterion_id` on `evidence`, `drafts`, and
`probe_flags` (FK → `criteria`, ON DELETE RESTRICT, matching Change B's stance for
these tables). Do NOT keep `competence_id` alongside. One meaning per table — the
same Option-X discipline settled in Change C. Competence is reached with one join
(`criterion → competence`).
- *Alternative considered:* keep `competence_id` and add a nullable `criterion_id`.
  Rejected — two grain keys create ambiguity about which is authoritative and
  invite drift; with zero data there is no migration benefit to the softer path.

**D3 — Drop `competences.level_id` and `Competence::level()`.**
The "one competence, one level" relation is simply wrong under the real model and
is now fully replaced by `Competence hasMany Criteria` (each criterion naming its
level). Removing it prevents a future reader from grading at competence×(single
level) by accident.
- *Alternative considered:* leave it as a deprecated nullable column. Rejected —
  dead, misleading columns are exactly what R5 ("boring, minimal") argues against,
  and nothing depends on it.

**D4 — Edit the Change B migration files; do not add alter-migrations.**
Operator decision. Pre-launch with zero data, the cleanest end state is a single
coherent migration set, so: drop `level_id` from the competences migration; add a
new `criteria` migration ordered AFTER `levels`+`competences` and BEFORE
evidence/drafts/probe_flags; swap `competence_id → criterion_id` in those three
migrations. Validated by `php artisan migrate:fresh` (and a plain re-`migrate`).
- *Alternative considered:* append-only alter-migrations. Rejected for a worse
  final schema; only justified when production data exists.

**D5 — Hard-rule guarantees move to criterion grain, unchanged in substance.**
- R1: `drafts.ai_status` keeps its DB DEFAULT `'à vérifier'`, and
  `Draft::finalVerdict()` still returns null until `finalized_at`. Only the FK
  changes (`competence_id → criterion_id`).
- R3: `evidence` (file+line, no student identity) and `probe_flags`
  (`kind`+`context_payload`, no file/line) stay structurally distinct; both now
  key on `criterion_id`. No `student_repo_id`/persona on either.
- R4: `criteria` carries no persona and no student-identity column; the only path
  to a `StudentRepo` from a criterion-grained row stays `→ run → student_repo`.

## Risks / Trade-offs

- **[Editing archived-change migrations rewrites history of a merged change]** →
  Acceptable and intended per D4: pre-launch, zero data, `migrate:fresh` is the
  contract. The `domain-model` spec is updated via a MODIFIED delta so the
  canonical spec stays the source of truth, not the old migration text.
- **[Re-grain touches four migrations + four models + factories + tests at once]**
  → Kept atomic and under the 15-task limit; each table's change is mechanical and
  independently asserted in `DomainSchemaTest`. `migrate:fresh` + the full test
  suite is the single gate.
- **[FK delete semantics drift]** → Deliberately preserved: `criteria` parents
  cascade (criterion meaningless without competence/level); the Pass-1 tables keep
  RESTRICT on their grain FK (a graded criterion should not silently vanish),
  matching Change B's evidence/draft/probe_flag stance.
- **[Forgetting a downstream consumer of `competence_id`]** → None exists yet:
  `RepoIntakeService` (Change C) writes zero Evidence/Draft rows, and no Pass 1
  code exists. This change lands before any consumer, by design.

## Sandbox / Security Impact

**None.** No `apps/runner` files, no Docker, no egress, no secrets, no
network boundary. Pure `apps/web` schema + model change. The v0 "trusted repos
only on local Laragon host" constraint is untouched; sandbox hardening remains
deferred to `change/runner-sandbox` (requires human review).

## Migration Plan

1. Edit `..._100002_create_competences_table.php`: remove the `level_id` column.
2. Add `..._100002b_create_criteria_table.php` (or renumber) ordered after
   competences, before evidence: `criteria` table per D1.
3. Edit `..._100006/100007/100008` migrations: `competence_id → criterion_id`
   (FK → `criteria`, RESTRICT).
4. `php artisan migrate:fresh` on the local MySQL; then run `php artisan test`.
5. Rollback: revert the branch (no production data, no deployed schema).
