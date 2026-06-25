## Why

The référentiel's real evaluable unit is the **critère d'évaluation**, which
belongs to a `(Competence, Level)` pair: a Competence spans three progressive
Levels (Niveau 1 émerger / 2 adapter / 3 transposer), and each Level carries its
own criteria. The Change B schema never modeled criteria — it has no criterion
entity, models `competences.level_id` as a single nullable "this competence sits
at one level" FK (the wrong shape), and keys Pass-1 evidence/drafts/probe_flags
on `competence_id`. Wiring the LLM Pass 1 against this would grade at competence
grain — one verdict per competence instead of one per criterion. This must be
corrected **before** the Pass 1 change so Pass 1 grades at the right grain.
The Pass-1 tables are empty (pre-launch, zero data), so the re-grain is free now
and expensive later.

## What Changes

- **ADD** a `criteria` table — the evaluable unit, one row per
  `(competence, level)` criterion: `competence_id` (FK → competences, cascade),
  `level_id` (FK → levels, cascade), `code`, `label`, `description` (the critère
  text), `sort_order`, with a `unique(competence_id, level_id, code)` constraint.
- **ADD** a `Criterion` Eloquent model + factory; `Competence hasMany Criteria`,
  `Level hasMany Criteria`, `Criterion belongsTo Competence` and `belongsTo Level`.
- **BREAKING** — **DROP** `competences.level_id` and `Competence::level()`. The
  "one competence, one level" relation is wrong: a competence spans all three
  levels via its criteria. (Pre-launch, no data depends on it.)
- **BREAKING** — **RE-GRAIN** `evidence`, `drafts`, and `probe_flags`: replace
  `competence_id` with `criterion_id` as the single grain key (FK → criteria).
  Competence stays reachable via `criterion → competence`. One meaning per table,
  same discipline as Change C's Option X. Pass 1 now produces evidence and a draft
  verdict **per criterion**; level/competence attainment become roll-ups.
- Land by **editing the existing Change B migration files** + `migrate:fresh`
  (operator decision; zero data, no data migration). Update `DomainSchemaTest`.

## Capabilities

### New Capabilities
<!-- none — this extends an existing capability rather than introducing a new one -->

### Modified Capabilities
- `domain-model`: ADD a Criteria requirement (the `(Competence, Level)` evaluable
  unit + `Criterion` model/factory). MODIFY the Competence requirement (drop the
  `level_id` FK and `belongsTo Level`). MODIFY the Evidence, Draft, and ProbeFlag
  requirements to key on `criterion_id` instead of `competence_id` (single grain
  key; competence reachable via `criterion`). R1/R3/R4 guarantees unchanged in
  substance — they now attach at criterion grain.

## Impact

- **Migrations**: edit `2026_06_24_100002_create_competences_table.php` (drop
  `level_id`), `..._100006_create_evidence_table.php`,
  `..._100007_create_drafts_table.php`, `..._100008_create_probe_flags_table.php`
  (swap `competence_id` → `criterion_id`); add a new `criteria` table migration
  ordered after competences+levels and before evidence/drafts/probe_flags.
  Requires `php artisan migrate:fresh`.
- **Models**: new `Criterion`; edit `Competence` (drop `level()`, add `criteria()`),
  `Level` (add `criteria()`), `Evidence`/`Draft`/`ProbeFlag` (swap `competence()`
  → `criterion()`). New `CriterionFactory`; edit the evidence/draft/probe_flag
  factories.
- **Tests**: update `tests/Feature/DomainSchemaTest.php` (criterion grain,
  criteria relations, dropped `level_id`); R1/R3/R4 assertions re-pointed at
  `criterion_id`.
- **Spec**: MODIFY `openspec/specs/domain-model/spec.md` on archive.
- **Hard rules**: R1 (drafts `ai_status` default `'à vérifier'` + `finalVerdict()`
  guard unchanged), R3 (evidence vs probe_flags stay structurally separate, now on
  criterion), R4 (no persona on criteria/evidence; reachable identity path
  unchanged). R2/R5 untouched. **Sandbox/security boundary: none** — no
  `apps/runner`, Docker, egress, or secrets.
- **Sequencing**: blocks the Pass-1 LLM wiring change, which must grade at
  criterion grain.
