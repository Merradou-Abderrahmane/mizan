## ADDED Requirements

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
`level_id` (bigint, FK → `levels.id`, nullable, ON DELETE SET NULL),
`code` (string, not null), `label` (string, not null), `description` (text,
nullable), `created_at`, and `updated_at`.

The `Competence` model SHALL have a `belongsTo` relation to `Referentiel` and a
`belongsTo` relation to `Level` (nullable). The `Referentiel` model SHALL have
a `hasMany` relation to `Competence`.

#### Scenario: Competence table migrates cleanly
- **GIVEN** the `referentiels` and `levels` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `competences` table SHALL exist
- **AND** it SHALL have a non-nullable `referentiel_id` foreign key
- **AND** it SHALL have a nullable `level_id` foreign key

#### Scenario: Competence belongs to Referentiel and optionally to Level
- **GIVEN** a persisted `Competence` linked to a `Referentiel` and a `Level`
- **WHEN** `$competence->referentiel` and `$competence->level` are accessed
- **THEN** both SHALL return the related model instances

#### Scenario: Competence without a Level
- **GIVEN** a persisted `Competence` with `level_id` set to null
- **WHEN** `$competence->level` is accessed
- **THEN** it SHALL return null

---

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
`hasMany` relation to `Draft`, and a `hasMany` relation to `ProbeFlag`.

#### Scenario: Run table migrates cleanly
- **GIVEN** the `student_repos` and `briefs` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `runs` table SHALL exist
- **AND** it SHALL have `student_repo_id` and `brief_id` foreign keys

#### Scenario: Run belongs to StudentRepo and Brief
- **GIVEN** a persisted `Run` linked to a `StudentRepo` and a `Brief`
- **WHEN** `$run->studentRepo` and `$run->brief` are accessed
- **THEN** both SHALL return the related model instances

#### Scenario: Run has many Evidence, Draft, and ProbeFlag
- **GIVEN** a persisted `Run` with two `Evidence`, one `Draft`, and one
  `ProbeFlag`
- **WHEN** `$run->evidence`, `$run->drafts`, and `$run->probeFlags` are
  accessed
- **THEN** each SHALL return a Collection of the correct type

---

### Requirement: Evidence table — Pass 1 blind, file+line citations, no student identity
The system SHALL create an `evidence` table with columns: `id` (bigint, PK),
`run_id` (bigint, FK → `runs.id`, ON DELETE CASCADE),
`competence_id` (bigint, FK → `competences.id`, ON DELETE RESTRICT),
`check_id` (string, not null — the runner check that produced this evidence,
e.g., `composer_install`, `readme_real`),
`file_path` (string, nullable — relative path from repo root, or null when
not applicable),
`line_number` (integer, nullable — 1-based line number, or null when not
applicable),
`excerpt` (text, nullable — a short string excerpt, max 500 chars),
`kind` (string, not null — one of: `stdout`, `stderr`, `git`, `filesystem`,
`command`),
`status` (string, not null — one of: `pass`, `fail`, `skip`),
`message` (string, nullable), `created_at`, and `updated_at`.

The `evidence` table SHALL NOT have a `student_repo_id` column. The `evidence`
table SHALL NOT have an `operator_persona` column. This enforces R3 (Pass 1 is
blind — zero student identity in context) and R4 (persona never enters Pass 1)
at the schema level.

The `Evidence` model SHALL have a `belongsTo` relation to `Run` and a
`belongsTo` relation to `Competence`.

#### Scenario: Evidence table migrates cleanly
- **GIVEN** the `runs` and `competences` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `evidence` table SHALL exist
- **AND** it SHALL have `run_id` and `competence_id` foreign keys
- **AND** it SHALL NOT have a `student_repo_id` column
- **AND** it SHALL NOT have an `operator_persona` column

#### Scenario: Evidence cites file and line (R3)
- **GIVEN** a persisted `Evidence` record for a `readme_real` check that
  failed
- **WHEN** the record is loaded
- **THEN** `$evidence->file_path` SHALL equal `"README.md"`
- **AND** `$evidence->line_number` SHALL be null (no specific line for this
  check type) OR an integer ≥ 1

#### Scenario: Evidence has no student identity (R3 blind pass)
- **GIVEN** the `evidence` table schema
- **WHEN** the column list is inspected
- **THEN** there SHALL be no column named `student_repo_id`,
  `student_name`, `operator_persona`, or any other student-identity column
- **AND** the only way to reach a `StudentRepo` from `Evidence` is through
  `Run` (two joins)

---

### Requirement: Draft table — AI draft vs operator-finalized, default à vérifier (R1)
The system SHALL create a `drafts` table with columns: `id` (bigint, PK),
`run_id` (bigint, FK → `runs.id`, ON DELETE CASCADE),
`competence_id` (bigint, FK → `competences.id`, ON DELETE RESTRICT),
`ai_status` (string, not null, default `'à vérifier'` — the AI's draft
assessment, one of: `valide`, `non valide`, `à vérifier`),
`ai_raw_json` (json, nullable — the raw LLM response for auditability),
`ai_reasoning` (text, nullable — the AI's human-readable reasoning),
`operator_status` (string, nullable — the operator's finalized value, one of:
`valide`, `non valide`, `à vérifier`; null until finalized),
`operator_note` (text, nullable — the operator's note when overriding),
`finalized_at` (timestamp, nullable — non-null when the operator has
finalized; null means the draft is still a draft, NOT a verdict),
`created_at`, and `updated_at`.

The `ai_status` column SHALL have a database-level DEFAULT of `'à vérifier'`
so that even a raw INSERT with no application code produces a safe draft,
never a phantom `valide` (R1: "anything needing oral confirmation defaults to
à vérifier, never valide").

The `Draft` model SHALL have a `belongsTo` relation to `Run` and a
`belongsTo` relation to `Competence`.

The `Draft` model SHALL expose a `finalVerdict(): ?string` accessor that
returns `operator_status` if and only if `finalized_at` is non-null; otherwise
it SHALL return null. This guarantees that an un-finalized draft can never be
read as a final verdict by any caller (R1: "the LLM never emits a final
verdict; the operator finalizes, always").

#### Scenario: Draft table migrates cleanly
- **GIVEN** the `runs` and `competences` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `drafts` table SHALL exist
- **AND** it SHALL have separate `ai_status` and `operator_status` columns
- **AND** the `ai_status` column SHALL default to `'à vérifier'`
- **AND** the `operator_status` column SHALL be nullable
- **AND** the `finalized_at` column SHALL be nullable

#### Scenario: Un-finalized draft returns null from finalVerdict (R1)
- **GIVEN** a persisted `Draft` where `ai_status` is `'valide'` but
  `finalized_at` is null and `operator_status` is null
- **WHEN** `$draft->finalVerdict()` is called
- **THEN** it SHALL return null (NOT `'valide'` — the AI draft is never a
  final verdict)

#### Scenario: Finalized draft returns operator's value from finalVerdict (R1)
- **GIVEN** a persisted `Draft` where `operator_status` is `'non valide'` and
  `finalized_at` is set
- **WHEN** `$draft->finalVerdict()` is called
- **THEN** it SHALL return `'non valide'`

#### Scenario: ai_status defaults to à vérifier on raw insert (R1)
- **GIVEN** a database insert into `drafts` that does not specify `ai_status`
- **WHEN** the row is loaded
- **THEN** `ai_status` SHALL equal `'à vérifier'`
- **AND** `operator_status` SHALL be null
- **AND** `finalized_at` SHALL be null

#### Scenario: Draft belongs to Run and Competence
- **GIVEN** a persisted `Draft` linked to a `Run` and a `Competence`
- **WHEN** `$draft->run` and `$draft->competence` are accessed
- **THEN** both SHALL return the related model instances

---

### Requirement: ProbeFlag table — Pass 2 contextual, separate from Evidence (R3)
The system SHALL create a `probe_flags` table with columns: `id` (bigint, PK),
`run_id` (bigint, FK → `runs.id`, ON DELETE CASCADE),
`competence_id` (bigint, FK → `competences.id`, ON DELETE RESTRICT),
`kind` (string, not null — one of: `divergence`, `regression`),
`context_payload` (json, nullable — the Pass 2 contextual data that triggered
the flag, e.g., student history, level comparison; NEVER a re-grade),
`message` (text, nullable — human-readable explanation of the flag),
`created_at`, and `updated_at`.

The `probe_flags` table SHALL NOT have `file_path` or `line_number` columns.
Pass 2 probe flags are contextual, not file+line citations — they are
structurally distinct from Pass 1 `evidence` (R3: "two-pass grading, strictly
separated").

The `ProbeFlag` model SHALL have a `belongsTo` relation to `Run` and a
`belongsTo` relation to `Competence`.

#### Scenario: ProbeFlag table migrates cleanly
- **GIVEN** the `runs` and `competences` tables already exist
- **WHEN** `php artisan migrate` is run
- **THEN** the `probe_flags` table SHALL exist
- **AND** it SHALL have `run_id` and `competence_id` foreign keys
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

#### Scenario: ProbeFlag belongs to Run and Competence
- **GIVEN** a persisted `ProbeFlag` linked to a `Run` and a `Competence`
- **WHEN** `$probeFlag->run` and `$probeFlag->competence` are accessed
- **THEN** both SHALL return the related model instances

---

### Requirement: Model factories for all domain entities
The system SHALL provide an Eloquent model factory for each of the 9 domain
models: `Referentiel`, `Level`, `Competence`, `Brief`, `StudentRepo`, `Run`,
`Evidence`, `Draft`, and `ProbeFlag`.

Each factory SHALL produce a valid, persistable model instance with sensible
defaults (e.g., `Draft` factory defaults `ai_status` to `'à vérifier'`,
`operator_status` to null, `finalized_at` to null). Each factory SHALL support
relational defaults so that `$factory->create()` can auto-create parent
relations where needed (e.g., `Run::factory()->create()` auto-creates a
`StudentRepo` and `Brief` if not provided).

#### Scenario: Draft factory defaults to safe à vérifier state (R1)
- **GIVEN** the `Draft` factory
- **WHEN** `Draft::factory()->create()` is called with no explicit overrides
- **THEN** the persisted `ai_status` SHALL be `'à vérifier'`
- **AND** `operator_status` SHALL be null
- **AND** `finalized_at` SHALL be null

#### Scenario: Run factory auto-creates parent relations
- **GIVEN** the `Run` factory
- **WHEN** `Run::factory()->create()` is called with no explicit `student_repo_id`
  or `brief_id`
- **THEN** a `StudentRepo` and a `Brief` SHALL be auto-created and linked
- **AND** the persisted `Run` SHALL have non-null `student_repo_id` and
  `brief_id`
