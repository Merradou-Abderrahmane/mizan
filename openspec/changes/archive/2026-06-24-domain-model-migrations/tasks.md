## 1. Branch & scaffold

- [x] 1.1 Create feature branch `feat/change-b-domain-model` off `main`. Work only on this branch; never commit to `main`.
  - Check: `git branch --show-current` outputs `feat/change-b-domain-model`; `git log --oneline -1` shows the same base commit as `origin/main`.

- [x] 1.2 Create migration file `database/migrations/<timestamp>_create_referentiels_and_levels_tables.php` that creates `referentiels` (id, title, description nullable, timestamps) and `levels` (id, referentiel_id FK CASCADE, code, label, sort_order default 0, timestamps).
  - Check: `php artisan migrate` creates both tables; `Schema::hasTable('referentiels')` and `Schema::hasTable('levels')` are true; `Schema::hasColumn('levels', 'referentiel_id')` is true.

## 2. Competence, Brief, StudentRepo migrations

- [x] 2.1 Create migration `database/migrations/<timestamp>_create_competences_table.php` that creates `competences` (id, referentiel_id FK CASCADE, level_id FK SET NULL nullable, code, label, description nullable, timestamps).
  - Check: `php artisan migrate` creates the table; `Schema::hasColumn('competences', 'level_id')` is true and the column is nullable.

- [x] 2.2 Create migration `database/migrations/<timestamp>_create_briefs_table.php` that creates `briefs` (id, title, description nullable, referentiel_id FK RESTRICT, payload json nullable, timestamps).
  - Check: `php artisan migrate` creates the table; the `referentiel_id` foreign key has ON DELETE RESTRICT (inspect via `SHOW CREATE TABLE` or assert in a test that deleting a referenced referentiel throws).

- [x] 2.3 Create migration `database/migrations/<timestamp>_create_student_repos_table.php` that creates `student_repos` (id, name, clone_path, operator_persona nullable, timestamps). NO FKs — this is a root entity.
  - Check: `php artisan migrate` creates the table; `Schema::hasColumn('student_repos', 'operator_persona')` is true and the column is nullable.

## 3. Run, Evidence, Draft, ProbeFlag migrations

- [x] 3.1 Create migration `database/migrations/<timestamp>_create_runs_table.php` that creates `runs` (id, student_repo_id FK CASCADE, brief_id FK RESTRICT, status default 'pending', runner_report_json json nullable, started_at nullable, ended_at nullable, timestamps).
  - Check: `php artisan migrate` creates the table; `Schema::hasColumn('runs', 'runner_report_json')` is true; `status` column default is 'pending'.

- [x] 3.2 Create migration `database/migrations/<timestamp>_create_evidence_table.php` that creates `evidence` (id, run_id FK CASCADE, competence_id FK RESTRICT, check_id, file_path nullable, line_number nullable, excerpt nullable, kind, status, message nullable, timestamps). The table MUST NOT have `student_repo_id` or `operator_persona` columns (R3, R4).
  - Check: `php artisan migrate` creates the table; `Schema::hasColumn('evidence', 'student_repo_id')` is false; `Schema::hasColumn('evidence', 'operator_persona')` is false; `Schema::hasColumn('evidence', 'run_id')` is true.

- [x] 3.3 Create migration `database/migrations/<timestamp>_create_drafts_table.php` that creates `drafts` (id, run_id FK CASCADE, competence_id FK RESTRICT, ai_status default 'à vérifier', ai_raw_json json nullable, ai_reasoning nullable, operator_status nullable, operator_note nullable, finalized_at nullable, timestamps). The `ai_status` column MUST have a DB-level DEFAULT of 'à vérifier' (R1).
  - Check: `php artisan migrate` creates the table; a raw DB insert without specifying `ai_status` yields 'à vérifier' (assert via a test query); `Schema::hasColumn('drafts', 'operator_status')` is true and the column is nullable; `Schema::hasColumn('drafts', 'finalized_at')` is true and nullable.

- [x] 3.4 Create migration `database/migrations/<timestamp>_create_probe_flags_table.php` that creates `probe_flags` (id, run_id FK CASCADE, competence_id FK RESTRICT, kind, context_payload json nullable, message nullable, timestamps). The table MUST NOT have `file_path` or `line_number` columns (R3 — Pass 2 flags are structurally distinct from Pass 1 evidence).
  - Check: `php artisan migrate` creates the table; `Schema::hasColumn('probe_flags', 'file_path')` is false; `Schema::hasColumn('probe_flags', 'line_number')` is false; `Schema::hasColumn('probe_flags', 'context_payload')` is true.

## 4. Eloquent models — referentiel graph

- [x] 4.1 Create `app/Models/Referentiel.php` with `$fillable` (title, description), `hasMany` Level, `hasMany` Competence, `hasMany` Brief.
  - Check: PHPUnit test instantiates a Referentiel via factory, attaches 2 Levels + 1 Competence + 1 Brief, and asserts `$referentiel->levels->count() === 2`, `$referentiel->competences->count() === 1`, `$referentiel->briefs->count() === 1`.

- [x] 4.2 Create `app/Models/Level.php` with `$fillable` (referentiel_id, code, label, sort_order), `belongsTo` Referentiel.
  - Check: PHPUnit test asserts `$level->referentiel` returns the parent Referentiel instance.

- [x] 4.3 Create `app/Models/Competence.php` with `$fillable` (referentiel_id, level_id, code, label, description), `belongsTo` Referentiel, `belongsTo` Level (nullable).
  - Check: PHPUnit test asserts `$competence->referentiel` returns Referentiel; a Competence with null `level_id` has `$competence->level === null`.

## 5. Eloquent models — brief, student repo, run

- [x] 5.1 Create `app/Models/Brief.php` with `$fillable` (title, description, referentiel_id, payload), `$casts` for payload → 'array', `belongsTo` Referentiel.
  - Check: PHPUnit test asserts `$brief->referentiel` returns Referentiel; `$brief->payload` casts a JSON value to an array.

- [x] 5.2 Create `app/Models/StudentRepo.php` with `$fillable` (name, clone_path, operator_persona), `$hidden` containing 'operator_persona' (R4), `hasMany` Run.
  - Check: PHPUnit test creates a StudentRepo with `operator_persona = 'advanced'`, calls `toArray()`, and asserts the key 'operator_persona' is NOT present in the result.

- [x] 5.3 Create `app/Models/Run.php` with `$fillable` (student_repo_id, brief_id, status, runner_report_json, started_at, ended_at), `$casts` for runner_report_json → 'array', `belongsTo` StudentRepo, `belongsTo` Brief, `hasMany` Evidence, `hasMany` Draft, `hasMany` ProbeFlag.
  - Check: PHPUnit test creates a Run with 2 Evidence + 1 Draft + 1 ProbeFlag, asserts `$run->evidence->count() === 2`, `$run->drafts->count() === 1`, `$run->probeFlags->count() === 1`.

## 6. Eloquent models — evidence, draft, probe flag

- [x] 6.1 Create `app/Models/Evidence.php` with `$fillable` (run_id, competence_id, check_id, file_path, line_number, excerpt, kind, status, message), `belongsTo` Run, `belongsTo` Competence.
  - Check: PHPUnit test asserts `$evidence->run` and `$evidence->competence` return the related instances; `$evidence->file_path` and `$evidence->line_number` are nullable.

- [x] 6.2 Create `app/Models/Draft.php` with `$fillable` (run_id, competence_id, ai_status, ai_raw_json, ai_reasoning, operator_status, operator_note, finalized_at), `$casts` for ai_raw_json → 'array', `belongsTo` Run, `belongsTo` Competence, and a `finalVerdict(): ?string` method that returns `operator_status` when `finalized_at` is non-null, else null (R1).
  - Check: PHPUnit test — (a) Draft with `ai_status='valide'`, `finalized_at=null`, `operator_status=null` → `finalVerdict()` returns null; (b) Draft with `operator_status='non valide'`, `finalized_at=now()` → `finalVerdict()` returns 'non valide'.

- [x] 6.3 Create `app/Models/ProbeFlag.php` with `$fillable` (run_id, competence_id, kind, context_payload, message), `$casts` for context_payload → 'array', `belongsTo` Run, `belongsTo` Competence.
  - Check: PHPUnit test asserts `$probeFlag->run` and `$probeFlag->competence` return the related instances; `$probeFlag->context_payload` casts JSON to array.

## 7. Model factories

- [x] 7.1 Create factories for `Referentiel`, `Level`, `Competence`, `Brief` under `database/factories/`. Each with sensible defaults; Competence factory supports `level_id` nullable.
  - Check: `Referentiel::factory()->create()`, `Level::factory()->create()`, `Competence::factory()->create()`, `Brief::factory()->create()` each persist a valid record without errors.

- [x] 7.2 Create factory for `StudentRepo` with `operator_persona` defaulting to null (R4 — the persona is optional and operator-set, never auto-filled).
  - Check: `StudentRepo::factory()->create()` persists with `operator_persona === null`.

- [x] 7.3 Create factory for `Run` that auto-creates a `StudentRepo` and `Brief` (and their referentiel chain) if not provided. Default `status = 'pending'`.
  - Check: `Run::factory()->create()` persists a Run with non-null `student_repo_id` and `brief_id`, and the related StudentRepo + Brief + Referentiel exist.

- [x] 7.4 Create factories for `Evidence`, `Draft`, `ProbeFlag`. Draft factory defaults `ai_status = 'à vérifier'`, `operator_status = null`, `finalized_at = null` (R1). Each auto-creates a `Run` + `Competence` if not provided.
  - Check: `Draft::factory()->create()` persists with `ai_status === 'à vérifier'`, `operator_status === null`, `finalized_at === null`; `Evidence::factory()->create()` and `ProbeFlag::factory()->create()` persist valid records.

## 8. Hard-rule guarantee tests

- [x] 8.1 Create `tests/Feature/DomainSchemaTest.php` with a test asserting the `evidence` table has NO `student_repo_id` and NO `operator_persona` column (R3 blind pass + R4 persona exclusion). Use `Schema::hasColumn()`.
  - Check: PHPUnit green; the test explicitly names R3 and R4 in its method docblock or test name.

- [x] 8.2 Add a test asserting `probe_flags` has NO `file_path` / `line_number` columns and `evidence` has NO `kind` (divergence/regression) / `context_payload` columns — proving the two passes are structurally separate (R3).
  - Check: PHPUnit green; `Schema::hasColumn('probe_flags', 'file_path')` returns false, `Schema::hasColumn('evidence', 'context_payload')` returns false.

- [x] 8.3 Add a test asserting a raw DB insert into `drafts` (no `ai_status` specified) yields `'à vérifier'` and `operator_status` is null and `finalized_at` is null (R1 default).
  - Check: PHPUnit green; the test uses `DB::table('drafts')->insert([...])` with only required FK columns, then loads and asserts.

- [x] 8.4 Add a test asserting `StudentRepo` serialization omits `operator_persona` (R4). Create a StudentRepo with a non-null persona, call `toArray()`, assert the key is absent.
  - Check: PHPUnit green; test name or docblock references R4.

- [x] 8.5 Add a test asserting `Draft::finalVerdict()` returns null when un-finalized and returns the operator value when finalized (R1).
  - Check: PHPUnit green; two assertions (un-finalized → null, finalized → operator value).

## 9. Final validation & commit

- [x] 9.1 Run `php artisan test` (or `vendor/bin/phpunit`) in `apps/web` and confirm all tests green, including the existing ExampleTest and all new domain tests.
  - Check: 0 failures, 0 errors; test count increased by the new tests.

- [x] 9.2 Run `php artisan migrate:fresh --force` then `php artisan test` to confirm migrations are idempotent and the test suite passes from a clean schema.
  - Check: `migrate:fresh` completes without error; all tests green on the fresh schema.

- [x] 9.3 Run `openspec validate domain-model-migrations` and confirm the change is valid.
  - Check: outputs `Change 'domain-model-migrations' is valid`.

- [x] 9.4 Commit all new files on `feat/change-b-domain-model`, push the branch, and open a PR against `main`. Do NOT merge — the operator reviews and merges.
  - Check: `gh pr create --base main --head feat/change-b-domain-model` returns a PR URL; `git status` is clean; `git log --oneline -1` shows the commit on the feature branch.

- [x] 9.5 Append an entry to `docs/handoff-log.md` recording this change name, the branch + PR URL, the hard rules enforced in schema, and the next planned step.
  - Check: `docs/handoff-log.md` is non-empty and the new entry names `domain-model-migrations`, the PR URL, and references R1/R3/R4 schema enforcement.
