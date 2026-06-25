# domain-model Specification

## Purpose

The domain model is the persistence layer for the Mizan grading pipeline. It
provides the tables and Eloquent models that `apps/web` uses to store
référentiels (with their levels and criteria), briefs, student repos, runs,
Pass 1 evidence (file+line citations), Pass 2 probe flags (divergence/regression),
the AI's per-criterion draft assessments, and the per-competence Pass 1 result the
operator finalizes. A competence spans three progressive levels (Niveau 1 émerger /
2 adapter / 3 transposer); each `(competence, level)` pair carries its own criteria,
and Pass 1 grades at criterion grain. A competence is classified `technique`
(code-inspectable — graded by Pass 1) or `transversale` (operator-validated only);
a brief carries a per-competence target level via the `brief_competence` pivot.
Per-criterion AI assessments live in `drafts`; they roll up to a per-competence
`pass1_competence_results` row, which is the single point where the operator
finalizes one `valide`/`non valide` verdict per competence.

The schema enforces the hard rules structurally, not just in app logic:
- **R1** — the model never asserts a bare verdict: `drafts.ai_status` and
  `pass1_competence_results.ai_rollup_status` hold only `à vérifier` /
  `semble valide` / `semble non valide` (DB default `'à vérifier'`). The operator
  finalizes at competence grain on `pass1_competence_results`
  (`operator_status` + `finalized_at`); `Pass1CompetenceResult::finalVerdict()`
  returns null until finalized, so an un-finalized result can never be read as a
  final verdict. `drafts` is AI-only (no operator finalization columns).
- **R3** — Pass 1 blind evidence (`evidence` table, file+line, no
  `student_repo_id`) is structurally separate from Pass 2 contextual flags
  (`probe_flags` table, `kind`+`context_payload`, no file/line).
- **R4** — `student_repos.operator_persona` is `$hidden` on the model;
  `evidence` has no persona column and no FK to `student_repos`, so persona
  cannot reach Pass 1.
## Requirements
### Requirement: Referentiel and Level tables
The system SHALL create a `referentiels` table with columns: `id` (bigint, PK),
`title` (string, not null), `description` (text, nullable), `created_at`, and
`updated_at` timestamps.

The system SHALL create a `levels` table with columns: `id` (bigint, PK),
`referentiel_id` (bigint, FK → `referentiels.id`, ON DELETE CASCADE),
`code` (string, not null), `label` (string, not null), `sort_order` (integer,
default 0), `created_at`, and `updated_at`.

The `Referentiel` model SHALL have a `hasMany` relation to `Level`. The `Level`
model SHALL have a `belongsTo` relation to `Referentiel`.

#### Scenario: Referentiel and Level tables migrate cleanly
- **GIVEN** a fresh database with no domain tables
- **WHEN** `php artisan migrate` is run
- **THEN** both `referentiels` and `levels` tables SHALL exist
- **AND** the `levels` table SHALL have a foreign key column `referentiel_id`
  referencing `referentiels.id`

#### Scenario: Referentiel has many Levels
- **GIVEN** a persisted `Referentiel` with two child `Level` records
- **WHEN** `$referentiel->levels` is accessed
- **THEN** it SHALL return a Collection containing exactly those two `Level`
  instances

---

### Requirement: Competence table
The system SHALL create a `competences` table with columns: `id` (bigint, PK),
`referentiel_id` (bigint, FK → `referentiels.id`, ON DELETE CASCADE),
`code` (string, not null), `label` (string, not null), `description` (text,
nullable), `kind` (string, not null, DEFAULT `'transversale'` — one of:
`technique`, `transversale`), `created_at`, and `updated_at`.

The `kind` column classifies a competence as `technique` (code-inspectable —
eligible for LLM Pass 1) or `transversale` (soft-skill / posture / communication
— operator-validated only, NEVER graded by Pass 1). The DEFAULT is
`'transversale'` (safe-exclude): a competence is never auto-graded by Pass 1
unless explicitly classified `technique`. The `Competence` model SHALL expose a
query scope (e.g., `scopeTechnical`) returning only `kind = 'technique'`
competences.

The `competences` table SHALL NOT have a `level_id` column. A competence is not
"at" a single level — it spans all three progressive levels, and the
`(competence, level)` association is carried by the `criteria` table. The
`Competence` model SHALL have a `belongsTo` relation to `Referentiel` and a
`hasMany` relation to `Criterion`. The `Competence` model SHALL NOT have a
`level()` relation. The `Referentiel` model SHALL have a `hasMany` relation to
`Competence`.

#### Scenario: Competence table migrates cleanly
- **GIVEN** the `referentiels` and `levels` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `competences` table SHALL exist
- **AND** it SHALL have a non-nullable `referentiel_id` foreign key
- **AND** it SHALL have a `kind` column defaulting to `'transversale'`
- **AND** it SHALL NOT have a `level_id` column

#### Scenario: Competence belongs to Referentiel
- **GIVEN** a persisted `Competence` linked to a `Referentiel`
- **WHEN** `$competence->referentiel` is accessed
- **THEN** it SHALL return the related `Referentiel` instance

#### Scenario: kind defaults to transversale (safe-exclude) and technical scope filters
- **GIVEN** a `Competence` inserted with no explicit `kind`, and another with
  `kind = 'technique'`
- **WHEN** the rows are loaded and `Competence::technical()->get()` is queried
- **THEN** the first SHALL have `kind = 'transversale'`
- **AND** `Competence::technical()` SHALL return only the `technique` competence

#### Scenario: Competence reaches its levels through criteria, not a direct FK
- **GIVEN** a persisted `Competence`
- **WHEN** the `competences` table column list is inspected
- **THEN** there SHALL be no `level_id` column
- **AND** the competence's levels SHALL be reachable only via its `criteria`
  (`$competence->criteria` → each `$criterion->level`)

### Requirement: Brief table
The system SHALL create a `briefs` table with columns: `id` (bigint, PK),
`title` (string, not null), `description` (text, nullable),
`referentiel_id` (bigint, FK → `referentiels.id`, ON DELETE RESTRICT),
`payload` (json, nullable — the structured brief content for LLM context),
`created_at`, and `updated_at`.

The `Brief` model SHALL have a `belongsTo` relation to `Referentiel`. The
`Referentiel` model SHALL have a `hasMany` relation to `Brief`.

#### Scenario: Brief table migrates cleanly
- **GIVEN** the `referentiels` table already exists
- **WHEN** `php artisan migrate` is run
- **THEN** the `briefs` table SHALL exist
- **AND** it SHALL have a `referentiel_id` foreign key with ON DELETE RESTRICT

#### Scenario: Brief belongs to Referentiel
- **GIVEN** a persisted `Brief` linked to a `Referentiel`
- **WHEN** `$brief->referentiel` is accessed
- **THEN** it SHALL return the related `Referentiel` instance

---

### Requirement: StudentRepo table with operator-only persona column
The system SHALL create a `student_repos` table with columns: `id` (bigint,
PK), `name` (string, not null), `clone_path` (string, not null),
`operator_persona` (string, nullable), `created_at`, and `updated_at`.

The `operator_persona` column SHALL be nullable and SHALL represent the
operator's private tag (R4). The `StudentRepo` model SHALL include
`operator_persona` in its `$hidden` array so that serialization (e.g.,
`toArray()`, `toJson()`) omits it.

The `StudentRepo` model SHALL have a `hasMany` relation to `Run`.

#### Scenario: StudentRepo table migrates cleanly
- **GIVEN** a fresh database
- **WHEN** `php artisan migrate` is run
- **THEN** the `student_repos` table SHALL exist
- **AND** it SHALL have a nullable `operator_persona` column

#### Scenario: operator_persona is hidden from serialization (R4)
- **GIVEN** a persisted `StudentRepo` with `operator_persona` set to `"advanced"`
- **WHEN** `$studentRepo->toArray()` is called
- **THEN** the resulting array SHALL NOT contain the key `operator_persona`

#### Scenario: operator_persona is nullable
- **GIVEN** a `StudentRepo` created without an `operator_persona` value
- **WHEN** the record is persisted and reloaded
- **THEN** `$studentRepo->operator_persona` SHALL be null

---

### Requirement: Run table
The system SHALL create a `runs` table with columns: `id` (bigint, PK),
`student_repo_id` (bigint, FK → `student_repos.id`, ON DELETE CASCADE),
`brief_id` (bigint, FK → `briefs.id`, ON DELETE RESTRICT),
`status` (string, not null, default `'pending'`),
`runner_report_json` (json, nullable — the raw runner output stored for
audit), `started_at` (timestamp, nullable), `ended_at` (timestamp, nullable),
`created_at`, and `updated_at`.

The `Run` model SHALL have a `belongsTo` relation to `StudentRepo`, a
`belongsTo` relation to `Brief`, a `hasMany` relation to `Evidence`, a
`hasMany` relation to `Draft`, a `hasMany` relation to `ProbeFlag`, and a
`hasMany` relation to `Pass1CompetenceResult`.

#### Scenario: Run table migrates cleanly
- **GIVEN** the `student_repos` and `briefs` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `runs` table SHALL exist
- **AND** it SHALL have `student_repo_id` and `brief_id` foreign keys

#### Scenario: Run belongs to StudentRepo and Brief
- **GIVEN** a persisted `Run` linked to a `StudentRepo` and a `Brief`
- **WHEN** `$run->studentRepo` and `$run->brief` are accessed
- **THEN** both SHALL return the related model instances

#### Scenario: Run has many Evidence, Draft, ProbeFlag, and Pass1CompetenceResult
- **GIVEN** a persisted `Run` with two `Evidence`, one `Draft`, one `ProbeFlag`,
  and one `Pass1CompetenceResult`
- **WHEN** `$run->evidence`, `$run->drafts`, `$run->probeFlags`, and
  `$run->pass1CompetenceResults` are accessed
- **THEN** each SHALL return a Collection of the correct type

### Requirement: Evidence table — Pass 1 blind, file+line citations, no student identity
The system SHALL create an `evidence` table with columns: `id` (bigint, PK),
`run_id` (bigint, FK → `runs.id`, ON DELETE CASCADE),
`criterion_id` (bigint, FK → `criteria.id`, ON DELETE RESTRICT — the evaluable
unit this evidence supports),
`check_id` (string, nullable — present only when the evidence originated from a
named runner check; null for LLM-cited Pass 1 evidence),
`file_path` (string, nullable — relative path from repo root),
`line_number` (integer, nullable — 1-based line number),
`excerpt` (text, nullable — a short string excerpt, max 500 chars),
`kind` (string, nullable — when set, one of: `stdout`, `stderr`, `git`,
`filesystem`, `command`),
`status` (string, nullable — when set, one of: `pass`, `fail`, `skip`),
`message` (string, nullable — the LLM citation `note`, or a runner message),
`created_at`, and `updated_at`.

The Pass 1 evidence item is an LLM citation of the form `{file, line, note}`:
`file_path` + `line_number` locate the code, and `message` holds the `note`.
The runner-oriented columns `check_id`, `kind`, and `status` SHALL be nullable so
LLM-cited evidence can omit them (runner check output itself is stored in
`runner_report_json` on the `Run`, not here — per the repo-intake design).

The `evidence` table SHALL be keyed on `criterion_id` as its single grain key —
it SHALL NOT have a `competence_id` column; the competence is reachable via
`criterion → competence`. The `evidence` table SHALL NOT have a `student_repo_id`
column. The `evidence` table SHALL NOT have an `operator_persona` column. This
enforces R3 (Pass 1 is blind — zero student identity in context) and R4 (persona
never enters Pass 1) at the schema level, at the criterion grain.

The `Evidence` model SHALL have a `belongsTo` relation to `Run` and a
`belongsTo` relation to `Criterion`.

#### Scenario: Evidence table migrates cleanly
- **GIVEN** the `runs` and `criteria` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `evidence` table SHALL exist
- **AND** it SHALL have `run_id` and `criterion_id` foreign keys
- **AND** `check_id`, `kind`, and `status` SHALL be nullable
- **AND** it SHALL NOT have a `competence_id` column
- **AND** it SHALL NOT have a `student_repo_id` column
- **AND** it SHALL NOT have an `operator_persona` column

#### Scenario: LLM-cited evidence stores file, line, and note (R3)
- **GIVEN** a persisted `Evidence` for an LLM citation with `file_path =
  "app/Models/User.php"`, `line_number = 42`, and `message = "uses Eloquent
  relation"`, and `check_id`/`kind`/`status` left null
- **WHEN** the record is loaded
- **THEN** `$evidence->criterion` SHALL return the related `Criterion`
- **AND** `$evidence->file_path` SHALL equal `"app/Models/User.php"`
- **AND** `$evidence->line_number` SHALL equal `42`
- **AND** `$evidence->message` SHALL equal `"uses Eloquent relation"`

#### Scenario: Evidence has no student identity (R3 blind pass)
- **GIVEN** the `evidence` table schema
- **WHEN** the column list is inspected
- **THEN** there SHALL be no column named `student_repo_id`,
  `student_name`, `operator_persona`, or any other student-identity column
- **AND** the only way to reach a `StudentRepo` from `Evidence` is through
  `Run` (two joins)

### Requirement: Draft table — AI-only per-criterion assessment, default à vérifier (R1)
The system SHALL create a `drafts` table with columns: `id` (bigint, PK),
`run_id` (bigint, FK → `runs.id`, ON DELETE CASCADE),
`criterion_id` (bigint, FK → `criteria.id`, ON DELETE RESTRICT — the evaluable
unit this draft is for),
`ai_status` (string, not null, default `'à vérifier'` — the AI's per-criterion
assessment, one of: `à vérifier`, `semble valide`, `semble non valide`),
`ai_raw_json` (json, nullable — the raw LLM response for auditability),
`ai_reasoning` (text, nullable — the AI's human-readable reasoning),
`created_at`, and `updated_at`.

The `drafts` table is **AI-only**: it holds the model's per-criterion assessment
and SHALL NOT have `operator_status`, `operator_note`, or `finalized_at` columns.
Operator finalization happens at competence grain on `pass1_competence_results`
(its `finalVerdict()` is the single finalization point); per-criterion drafts are
inputs to that rollup, not a finalization grain.

The `ai_status` column's allowed values SHALL be only `à vérifier`,
`semble valide`, or `semble non valide` — the model NEVER asserts a bare
`valide`/`non valide` verdict (R1). It SHALL have a database-level DEFAULT of
`'à vérifier'` so that even a raw INSERT produces a safe draft (R1: "anything
needing oral confirmation defaults to à vérifier, never valide"). A criterion
with no surviving evidence is left at `à vérifier`.

The `drafts` table SHALL be keyed on `criterion_id` as its single grain key — it
SHALL NOT have a `competence_id` column.

The `Draft` model SHALL have a `belongsTo` relation to `Run` and a `belongsTo`
relation to `Criterion`. The `Draft` model SHALL NOT expose a `finalVerdict()`
accessor (finalization is on `Pass1CompetenceResult`).

#### Scenario: Draft table migrates cleanly and is AI-only
- **GIVEN** the `runs` and `criteria` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `drafts` table SHALL exist
- **AND** it SHALL have a `criterion_id` foreign key and SHALL NOT have a
  `competence_id` column
- **AND** the `ai_status` column SHALL default to `'à vérifier'`
- **AND** it SHALL NOT have `operator_status`, `operator_note`, or `finalized_at`
  columns

#### Scenario: ai_status defaults to à vérifier on raw insert (R1)
- **GIVEN** a database insert into `drafts` that does not specify `ai_status`
- **WHEN** the row is loaded
- **THEN** `ai_status` SHALL equal `'à vérifier'`

#### Scenario: ai_status uses only hedged AI values (R1)
- **GIVEN** the contract for `drafts.ai_status`
- **WHEN** the model writes an assessment
- **THEN** the value SHALL be one of `à vérifier`, `semble valide`, or
  `semble non valide`
- **AND** it SHALL NEVER be a bare `valide` or `non valide` (those are operator
  values on `pass1_competence_results`)

#### Scenario: Draft belongs to Run and Criterion
- **GIVEN** a persisted `Draft` linked to a `Run` and a `Criterion`
- **WHEN** `$draft->run` and `$draft->criterion` are accessed
- **THEN** both SHALL return the related model instances

### Requirement: ProbeFlag table — Pass 2 contextual, separate from Evidence (R3)
The system SHALL create a `probe_flags` table with columns: `id` (bigint, PK),
`run_id` (bigint, FK → `runs.id`, ON DELETE CASCADE),
`criterion_id` (bigint, FK → `criteria.id`, ON DELETE RESTRICT — the evaluable
unit this flag concerns),
`kind` (string, not null — one of: `divergence`, `regression`),
`context_payload` (json, nullable — the Pass 2 contextual data that triggered
the flag, e.g., student history, level comparison; NEVER a re-grade),
`message` (text, nullable — human-readable explanation of the flag),
`created_at`, and `updated_at`.

The `probe_flags` table SHALL be keyed on `criterion_id` as its single grain key —
it SHALL NOT have a `competence_id` column. The `probe_flags` table SHALL NOT have
`file_path` or `line_number` columns. Pass 2 probe flags are contextual, not
file+line citations — they are structurally distinct from Pass 1 `evidence`
(R3: "two-pass grading, strictly separated").

The `ProbeFlag` model SHALL have a `belongsTo` relation to `Run` and a
`belongsTo` relation to `Criterion`.

#### Scenario: ProbeFlag table migrates cleanly
- **GIVEN** the `runs` and `criteria` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `probe_flags` table SHALL exist
- **AND** it SHALL have `run_id` and `criterion_id` foreign keys and SHALL NOT
  have a `competence_id` column
- **AND** it SHALL have a `kind` column
- **AND** it SHALL NOT have `file_path` or `line_number` columns

#### Scenario: ProbeFlag is structurally separate from Evidence (R3)
- **GIVEN** the `probe_flags` and `evidence` table schemas
- **WHEN** their column lists are compared
- **THEN** `probe_flags` SHALL NOT have `file_path`, `line_number`, or
  `excerpt` columns
- **AND** `evidence` SHALL NOT have `kind` (in the divergence/regression
  sense), `context_payload`, or `regression` columns
- **AND** the two tables SHALL share no merged notes/blob column
- **AND** both SHALL key on `criterion_id`

#### Scenario: ProbeFlag belongs to Run and Criterion
- **GIVEN** a persisted `ProbeFlag` linked to a `Run` and a `Criterion`
- **WHEN** `$probeFlag->run` and `$probeFlag->criterion` are accessed
- **THEN** both SHALL return the related model instances

### Requirement: Model factories for all domain entities
The system SHALL provide an Eloquent model factory for each of the 11 domain
models: `Referentiel`, `Level`, `Competence`, `Criterion`, `Brief`,
`StudentRepo`, `Run`, `Evidence`, `Draft`, `ProbeFlag`, and
`Pass1CompetenceResult`.

Each factory SHALL produce a valid, persistable model instance with sensible
defaults (e.g., the `Draft` factory defaults `ai_status` to `'à vérifier'`; the
`Pass1CompetenceResult` factory defaults `ai_rollup_status` to `'à vérifier'`,
`operator_status` to null, and `finalized_at` to null; the `Competence` factory
sets a `kind`). Each factory SHALL support relational defaults so that
`$factory->create()` can auto-create parent relations where needed (e.g.,
`Run::factory()->create()` auto-creates a `StudentRepo` and `Brief`;
`Criterion::factory()->create()` auto-creates a `Competence` and `Level`;
`Evidence`/`Draft` factories auto-create a `Criterion`;
`Pass1CompetenceResult::factory()->create()` auto-creates a `Run`, `Competence`,
and `Level`).

#### Scenario: Draft factory defaults to safe à vérifier state (R1)
- **GIVEN** the `Draft` factory
- **WHEN** `Draft::factory()->create()` is called with no explicit overrides
- **THEN** the persisted `ai_status` SHALL be `'à vérifier'`

#### Scenario: Pass1CompetenceResult factory defaults to safe un-finalized state (R1)
- **GIVEN** the `Pass1CompetenceResult` factory
- **WHEN** `Pass1CompetenceResult::factory()->create()` is called with no overrides
- **THEN** the persisted `ai_rollup_status` SHALL be `'à vérifier'`
- **AND** `operator_status` SHALL be null
- **AND** `finalized_at` SHALL be null
- **AND** a `Run`, `Competence`, and `Level` SHALL be auto-created and linked

#### Scenario: Criterion factory auto-creates its (Competence, Level) parents
- **GIVEN** the `Criterion` factory
- **WHEN** `Criterion::factory()->create()` is called with no explicit
  `competence_id` or `level_id`
- **THEN** a `Competence` and a `Level` SHALL be auto-created and linked
- **AND** the persisted `Criterion` SHALL have non-null `competence_id` and `level_id`

#### Scenario: Run factory auto-creates parent relations
- **GIVEN** the `Run` factory
- **WHEN** `Run::factory()->create()` is called with no explicit `student_repo_id`
  or `brief_id`
- **THEN** a `StudentRepo` and a `Brief` SHALL be auto-created and linked
- **AND** the persisted `Run` SHALL have non-null `student_repo_id` and
  `brief_id`

### Requirement: Criteria table — the evaluable unit per (Competence, Level) pair
The system SHALL create a `criteria` table with columns: `id` (bigint, PK),
`competence_id` (bigint, FK → `competences.id`, ON DELETE CASCADE),
`level_id` (bigint, FK → `levels.id`, ON DELETE CASCADE),
`code` (string, not null — the criterion code, unique within its
`(competence, level)` cell, e.g., `C1`),
`label` (string, not null — short name of the critère d'évaluation),
`description` (text, nullable — the full critère text),
`sort_order` (integer, default 0), `created_at`, and `updated_at`.

The table SHALL enforce `UNIQUE (competence_id, level_id, code)` so that the same
`code` MAY repeat across levels but SHALL be unique within a single
`(competence, level)` pair. A criterion is the référentiel's evaluable unit: a
Competence spans three progressive Levels (Niveau 1 émerger / 2 adapter /
3 transposer), and each `(Competence, Level)` pair carries its own criteria.

The `Criterion` model SHALL have a `belongsTo` relation to `Competence` and a
`belongsTo` relation to `Level`. The `Competence` model SHALL have a `hasMany`
relation to `Criterion`. The `Level` model SHALL have a `hasMany` relation to
`Criterion`.

#### Scenario: Criteria table migrates cleanly
- **GIVEN** the `competences` and `levels` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `criteria` table SHALL exist
- **AND** it SHALL have a non-nullable `competence_id` foreign key referencing `competences.id`
- **AND** it SHALL have a non-nullable `level_id` foreign key referencing `levels.id`

#### Scenario: Criterion belongs to a (Competence, Level) pair
- **GIVEN** a persisted `Criterion` linked to a `Competence` and a `Level`
- **WHEN** `$criterion->competence` and `$criterion->level` are accessed
- **THEN** both SHALL return the related model instances

#### Scenario: Code is unique within a (competence, level) cell but may repeat across levels
- **GIVEN** a `Competence` with two `Level` records (N1, N2) and a `Criterion`
  `code = "C1"` under N1
- **WHEN** another `Criterion` `code = "C1"` is created under N2 for the same competence
- **THEN** it SHALL persist successfully
- **AND** WHEN a second `Criterion` `code = "C1"` is created under N1 for the same competence
- **THEN** the insert SHALL fail the unique constraint

#### Scenario: Competence has many Criteria across its levels
- **GIVEN** a persisted `Competence` with three `Criterion` records spread across
  its levels
- **WHEN** `$competence->criteria` is accessed
- **THEN** it SHALL return a Collection containing exactly those three `Criterion` instances

### Requirement: Brief assessment scope — Brief↔Competence target-level pivot
The system SHALL create a `brief_competence` pivot table with columns: `id`
(bigint, PK), `brief_id` (bigint, FK → `briefs.id`, ON DELETE CASCADE),
`competence_id` (bigint, FK → `competences.id`, ON DELETE CASCADE),
`level_id` (bigint, FK → `levels.id`, ON DELETE RESTRICT — the level this
competence is assessed at for this brief), `created_at`, and `updated_at`. The
table SHALL enforce `UNIQUE (brief_id, competence_id)` so a brief assesses a
given competence at exactly one target level.

This pivot defines, for a brief, both the assessment scope (which competences it
covers) and the target level per competence — different competences MAY be
assessed at different levels in one brief. The `Brief` model SHALL have a
`belongsToMany` relation to `Competence` that exposes the pivot's `level_id`
(`withPivot('level_id')`).

#### Scenario: brief_competence table migrates cleanly
- **GIVEN** the `briefs`, `competences`, and `levels` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `brief_competence` table SHALL exist
- **AND** it SHALL have `brief_id`, `competence_id`, and `level_id` foreign keys
- **AND** it SHALL enforce a unique `(brief_id, competence_id)` constraint

#### Scenario: A brief assesses competences at per-competence target levels
- **GIVEN** a `Brief` linked to competence A at level N1 and competence B at level N2
- **WHEN** `$brief->competences` is accessed
- **THEN** it SHALL return both competences
- **AND** the pivot SHALL expose `level_id` so the target level for A is N1 and for B is N2

#### Scenario: A competence cannot be assessed at two levels in one brief
- **GIVEN** a `Brief` already linked to competence A at level N1
- **WHEN** the same `(brief, competence A)` pair is inserted again at level N2
- **THEN** the insert SHALL fail the unique `(brief_id, competence_id)` constraint

### Requirement: Pass1CompetenceResult table — competence rollup + operator finalization (R1)
The system SHALL create a `pass1_competence_results` table with columns: `id`
(bigint, PK), `run_id` (bigint, FK → `runs.id`, ON DELETE CASCADE),
`competence_id` (bigint, FK → `competences.id`, ON DELETE RESTRICT),
`level_id` (bigint, FK → `levels.id`, ON DELETE RESTRICT — the level assessed,
snapshotted on the result),
`ai_rollup_status` (string, not null, DEFAULT `'à vérifier'` — the AI's
competence-level rollup, one of: `à vérifier`, `semble valide`,
`semble non valide`),
`confidence` (decimal, nullable — the model's self-reported confidence, 0..1),
`probe_questions` (json, nullable — tiered oral questions the operator may ask;
distinct from Pass 2 `probe_flags`),
`raw_json` (json, nullable — the full Pass 1 LLM response for this competence,
for audit),
`operator_status` (string, nullable — the operator's finalized value, one of:
`valide`, `non valide`, `à vérifier`; null until finalized),
`operator_note` (text, nullable), `finalized_at` (timestamp, nullable),
`created_at`, and `updated_at`. The table SHALL enforce
`UNIQUE (run_id, competence_id)` — one Pass 1 result per competence per run.

The `ai_rollup_status` column SHALL have a database-level DEFAULT of
`'à vérifier'`, and its allowed AI values SHALL be only `à vérifier`,
`semble valide`, or `semble non valide` — the model NEVER asserts a bare
`valide`/`non valide` verdict (R1). The `pass1_competence_results` table SHALL
NOT have any `operator_persona` or student-identity column (R4); a `StudentRepo`
is reachable only via `run → student_repo`.

The `Pass1CompetenceResult` model SHALL have a `belongsTo` relation to `Run`, a
`belongsTo` relation to `Competence`, and a `belongsTo` relation to `Level`. The
`Run` model SHALL have a `hasMany` relation to `Pass1CompetenceResult`.

The `Pass1CompetenceResult` model SHALL expose a `finalVerdict(): ?string`
accessor that returns `operator_status` if and only if `finalized_at` is
non-null; otherwise it SHALL return null. This is the single finalization point
for a competence: an un-finalized result can never be read as a final verdict
(R1: "the LLM never emits a final verdict; the operator finalizes, always").

#### Scenario: pass1_competence_results table migrates cleanly
- **GIVEN** the `runs`, `competences`, and `levels` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `pass1_competence_results` table SHALL exist
- **AND** it SHALL have `run_id`, `competence_id`, and `level_id` foreign keys
- **AND** it SHALL enforce a unique `(run_id, competence_id)` constraint
- **AND** it SHALL NOT have an `operator_persona` or student-identity column

#### Scenario: ai_rollup_status defaults to à vérifier on raw insert (R1)
- **GIVEN** a database insert into `pass1_competence_results` that does not specify
  `ai_rollup_status`
- **WHEN** the row is loaded
- **THEN** `ai_rollup_status` SHALL equal `'à vérifier'`
- **AND** `operator_status` SHALL be null
- **AND** `finalized_at` SHALL be null

#### Scenario: Un-finalized result returns null from finalVerdict (R1)
- **GIVEN** a persisted `Pass1CompetenceResult` where `ai_rollup_status` is
  `'semble valide'` but `finalized_at` is null and `operator_status` is null
- **WHEN** `$result->finalVerdict()` is called
- **THEN** it SHALL return null (the AI rollup is never a final verdict)

#### Scenario: Finalized result returns operator's value from finalVerdict (R1)
- **GIVEN** a persisted `Pass1CompetenceResult` where `operator_status` is
  `'valide'` and `finalized_at` is set
- **WHEN** `$result->finalVerdict()` is called
- **THEN** it SHALL return `'valide'`

#### Scenario: Result belongs to Run, Competence, and Level
- **GIVEN** a persisted `Pass1CompetenceResult` linked to a `Run`, a `Competence`,
  and a `Level`
- **WHEN** `$result->run`, `$result->competence`, and `$result->level` are accessed
- **THEN** all three SHALL return the related model instances

