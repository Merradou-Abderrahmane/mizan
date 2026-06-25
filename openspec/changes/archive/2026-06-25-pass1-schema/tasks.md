## 1. Migrations (edit existing + add two)

- [x] 1.1 Edit `..._100002_create_competences_table.php`: add `kind` (string, not
  null, DEFAULT `'transversale'`).
- [x] 1.2 Edit `..._100006_create_evidence_table.php`: make `check_id`, `kind`,
  `status` nullable.
- [x] 1.3 Edit `..._100007_create_drafts_table.php`: drop `operator_status`,
  `operator_note`, `finalized_at`. Keep `ai_status` (DEFAULT `'à vérifier'`),
  `ai_raw_json`, `ai_reasoning`, `run_id`, `criterion_id`.
- [x] 1.4 Add `..._100009_create_brief_competence_table.php` (after briefs/
  competences/levels): `brief_id` (FK→briefs cascade), `competence_id`
  (FK→competences cascade), `level_id` (FK→levels restrict), timestamps,
  `unique(brief_id, competence_id)`.
- [x] 1.5 Add `..._100010_create_pass1_competence_results_table.php` (after runs/
  competences/levels): `run_id` (FK→runs cascade), `competence_id`
  (FK→competences restrict), `level_id` (FK→levels restrict), `ai_rollup_status`
  (string, DEFAULT `'à vérifier'`), `confidence` (decimal nullable),
  `probe_questions` (json nullable), `raw_json` (json nullable), `operator_status`
  (string nullable), `operator_note` (text nullable), `finalized_at` (timestamp
  nullable), timestamps, `unique(run_id, competence_id)`.
- [x] 1.6 Run `php artisan migrate:fresh` against local MySQL — confirm clean build,
  correct FK ordering.

## 2. Models

- [x] 2.1 Edit `app/Models/Competence.php`: add `kind` to `$fillable`; add a
  `scopeTechnical()` query scope returning `where('kind', 'technique')`.
- [x] 2.2 Edit `app/Models/Brief.php`: add `competences()` `belongsToMany`
  with `withPivot('level_id')` and `withTimestamps()`.
- [x] 2.3 Edit `app/Models/Draft.php`: remove `operator_status`/`operator_note`/
  `finalized_at` from `$fillable` and casts; remove the `finalVerdict()` accessor.
  Keep `criterion()`/`run()` relations and `ai_*` fields.
- [x] 2.4 Add `app/Models/Pass1CompetenceResult.php` (`$table =
  'pass1_competence_results'`): `$fillable` for all writable columns; casts
  (`probe_questions`→array, `raw_json`→array, `confidence`→float/decimal,
  `finalized_at`→datetime); `belongsTo` Run/Competence/Level; `finalVerdict():
  ?string` returning `operator_status` iff `finalized_at` non-null.
- [x] 2.5 Edit `app/Models/Run.php`: add `hasMany(Pass1CompetenceResult)` as
  `pass1CompetenceResults()`.

## 3. Factories

- [x] 3.1 Add `database/factories/Pass1CompetenceResultFactory.php`: auto-create
  `Run`, `Competence`, `Level`; `ai_rollup_status` default `'à vérifier'`,
  `operator_status`/`finalized_at` null, optional `confidence`/`probe_questions`.
- [x] 3.2 Edit `DraftFactory`: drop `operator_status`/`operator_note`/`finalized_at`
  and the `finalized()` state; keep `ai_status` default `'à vérifier'`.
- [x] 3.3 Edit `CompetenceFactory`: set `kind` (default `'transversale'`; add a
  `technical()` state for `'technique'`).

## 4. Tests

- [x] 4.1 `DomainSchemaTest`: `competences.kind` defaults to `'transversale'` on
  raw insert; `Competence::technical()` returns only `technique`.
- [x] 4.2 `DomainSchemaTest`: `brief_competence` migrates; `Brief::competences`
  exposes pivot `level_id`; per-competence target levels resolve; unique
  `(brief_id, competence_id)` rejects a duplicate pair.
- [x] 4.3 `DomainSchemaTest`: `pass1_competence_results` migrates with unique
  `(run_id, competence_id)` and no identity column; `ai_rollup_status` defaults
  `'à vérifier'`; `finalVerdict()` null until `finalized_at` set, then returns
  `operator_status`; `Run::pass1CompetenceResults` + belongsTo relations resolve.
- [x] 4.4 `DomainSchemaTest`: `drafts` is AI-only — no `operator_status`/
  `operator_note`/`finalized_at` columns, no `Draft::finalVerdict()`; `ai_status`
  defaults `'à vérifier'`. `evidence` `check_id`/`kind`/`status` nullable; an
  LLM-cited row (file+line+`message`, runner cols null) persists and loads.
  Update factory-coverage test to 11 models incl. `Pass1CompetenceResult`.

## 5. Verify

- [x] 5.1 `php artisan migrate:fresh` then `php artisan test` — all green.
- [x] 5.2 `openspec validate pass1-schema --strict` — passes.
- [x] 5.3 Confirm no `apps/runner/` file changed (sandbox boundary untouched).

## 6. Archive closeout (canonical spec must not disagree with migrations)

- [ ] 6.1 `openspec archive pass1-schema -y` — applies the ADDED/MODIFIED deltas
  into `openspec/specs/domain-model/spec.md`.
- [ ] 6.2 Hand-edit the canonical `## Purpose` (delta engine does NOT touch it):
  reflect that drafts is AI-only per-criterion and operator finalization is at
  competence grain on `pass1_competence_results`; mention `kind` (technical-only
  Pass 1 scope) and the brief target-level pivot. No stale claim that `drafts`
  holds the operator verdict.
- [ ] 6.3 Re-read the archived `domain-model/spec.md` and grep for stale terms:
  `drafts` `operator_status`/`finalized_at` outside the "SHALL NOT" guards;
  `ai_status` listed as `valide`/`non valide`. Expect zero.
- [ ] 6.4 `openspec validate domain-model --specs` (and `--strict` on the archive)
  — passes.
