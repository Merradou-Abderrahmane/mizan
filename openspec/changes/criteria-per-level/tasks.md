## 1. Migrations (edit Change B set + add criteria)

- [x] 1.1 Edit `2026_06_24_100002_create_competences_table.php`: remove the
  `level_id` column (and its FK). Leave `referentiel_id` and the rest intact.
- [x] 1.2 Add `2026_06_24_100002b_create_criteria_table.php` (ordered after
  competences+levels, before evidence): `criteria` table with `competence_id`
  (FK→competences, cascade), `level_id` (FK→levels, cascade), `code`, `label`,
  `description` (text, nullable), `sort_order` (default 0), timestamps, and a
  `unique(['competence_id','level_id','code'])` constraint.
- [x] 1.3 Edit `..._100006_create_evidence_table.php`: replace `competence_id`
  with `criterion_id` (FK→criteria, `restrictOnDelete()`). No other columns change.
- [x] 1.4 Edit `..._100007_create_drafts_table.php`: replace `competence_id`
  with `criterion_id` (FK→criteria, `restrictOnDelete()`). Keep `ai_status`
  DEFAULT `'à vérifier'`.
- [x] 1.5 Edit `..._100008_create_probe_flags_table.php`: replace `competence_id`
  with `criterion_id` (FK→criteria, `restrictOnDelete()`).
- [x] 1.6 Run `php artisan migrate:fresh` against local MySQL and confirm all
  tables build with no FK ordering errors.

## 2. Models

- [x] 2.1 Add `app/Models/Criterion.php`: `$fillable`
  (`competence_id`,`level_id`,`code`,`label`,`description`,`sort_order`),
  `belongsTo(Competence)`, `belongsTo(Level)`. (Set `$table = 'criteria'` —
  Laravel would otherwise pluralize to `criterions`.)
- [x] 2.2 Edit `app/Models/Competence.php`: remove `level()` and `level_id` from
  `$fillable`; add `hasMany(Criterion)` as `criteria()`.
- [x] 2.3 Edit `app/Models/Level.php`: add `hasMany(Criterion)` as `criteria()`.
- [x] 2.4 Edit `app/Models/Evidence.php`: replace `competence()` with
  `belongsTo(Criterion)` as `criterion()`; swap `competence_id`→`criterion_id`
  in `$fillable`.
- [x] 2.5 Edit `app/Models/Draft.php`: replace `competence()` with
  `belongsTo(Criterion)` as `criterion()`; swap `competence_id`→`criterion_id`
  in `$fillable`. Leave `finalVerdict()` and `ai_status` handling unchanged.
- [x] 2.6 Edit `app/Models/ProbeFlag.php`: replace `competence()` with
  `belongsTo(Criterion)` as `criterion()`; swap `competence_id`→`criterion_id`
  in `$fillable`.

## 3. Factories

- [x] 3.1 Add `database/factories/CriterionFactory.php`: auto-create
  `Competence` and `Level`, with `code`/`label`/`description`/`sort_order` defaults.
- [x] 3.2 Edit `CompetenceFactory`: drop `level_id`.
- [x] 3.3 Edit `EvidenceFactory`, `DraftFactory`, `ProbeFlagFactory`: default
  `criterion_id` via `Criterion::factory()` instead of `Competence::factory()`.

## 4. Tests

- [x] 4.1 Update `tests/Feature/DomainSchemaTest.php` for the criteria entity:
  criteria table migrates; `Criterion` belongs to a `(Competence, Level)` pair;
  `unique(competence_id,level_id,code)` allows the same code across levels but
  rejects a duplicate within one cell; `Competence::criteria()` and
  `Level::criteria()` return the right collections.
- [x] 4.2 Update the Competence tests: assert `competences` has NO `level_id`
  column and `Competence` has no `level()` relation; levels reachable only via
  criteria.
- [x] 4.3 Re-point the Evidence/Draft/ProbeFlag tests to `criterion_id`: assert
  each table has `criterion_id` and NO `competence_id`; `belongsTo Criterion`
  resolves; R1 (Draft `ai_status` default + `finalVerdict()` guard) and R3
  (evidence vs probe_flags separation, evidence has no student identity) still
  hold at criterion grain.
- [x] 4.4 Update the factory test to cover 10 models incl. `Criterion`, and the
  `Criterion::factory()` auto-parent scenario.

## 5. Verify

- [x] 5.1 Run `php artisan migrate:fresh` then `php artisan test` — all green.
- [x] 5.2 Run `openspec validate criteria-per-level --strict` — passes.
- [x] 5.3 Confirm no `apps/runner/` file changed (sandbox boundary untouched) and
  `git grep -n competence_id apps/web` shows zero hits in
  migrations/models/factories for the re-grained tables.

## 6. Archive closeout (canonical spec must not disagree with migrations)

- [ ] 6.1 Run `openspec archive criteria-per-level -y` to apply the MODIFIED/ADDED
  deltas into `openspec/specs/domain-model/spec.md`.
- [ ] 6.2 Hand-edit the canonical `## Purpose` of `domain-model/spec.md` (the delta
  engine does NOT touch Purpose prose): change "the AI draft vs operator-finalized
  verdict **for each competence**" → "**for each criterion** (the evaluable unit of
  a `(competence, level)` pair)", and add `criteria` to the list of stored entities.
  Verify the Purpose contains no remaining `competence`-grain wording for the Pass-1
  tables and no `level_id`.
- [ ] 6.3 Re-read the archived `domain-model/spec.md` end to end and grep it for
  stale terms: `level_id`, and any `competence_id` outside the explicit
  "SHALL NOT have a `competence_id`" guard clauses. Expect zero.
- [ ] 6.4 Run `openspec validate domain-model --specs` (and `--strict` on the
  archive) — passes.
