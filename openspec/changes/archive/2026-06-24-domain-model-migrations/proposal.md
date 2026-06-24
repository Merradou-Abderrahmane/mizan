## Why

The operator needs somewhere to persist a run before any LLM pass, web UI, or
draft finalization is built. Without the domain tables, `apps/web` has nowhere
to store the référentiel, brief, student repo, run, evidence, draft, and Pass 2
probe flags that R1/R3/R4 govern — every later change (LLM Pass 1, Pass 2, UI,
finalize flow) would have to invent the schema ad-hoc and the hard rules would
live only in app logic, not in the schema. This change stands up the migrations
+ Eloquent models for the full domain, with the hard rules encoded as column
constraints, separated tables, and a verdict-guarding accessor — **schema only,
no LLM calls, no web wiring, no queue**.

## What Changes

- Add Laravel migrations in `apps/web/database/migrations/` for the domain:
  `referentiels`, `levels`, `competences`, `briefs`, `student_repos`, `runs`,
  `evidence`, `drafts`, `probe_flags`.
- Add Eloquent models in `apps/web/app/Models/` for each table with relations
  (which `belongsTo` / `hasMany` / `hasOne`).
- Add model factories in `apps/web/database/factories/` for every new model so
  tests can build graph fixtures without hand-SQL.
- Add tests asserting: migrations run clean; relations resolve; and the three
  hard-rule guarantees hold **at the schema/model layer** (see Capabilities +
  design.md).
- **Inferred entity — flagging for approval:** `competences` is not in the
  user's listed entities but R3 mandates "per-competence" grading, so Evidence
  and Draft both need a competence FK. Modeling `Competence` (a `Referentiel`
  has many `Competence`, each optionally tied to a `Level`) is cleaner and more
  "boring" (R5) than free-text competence codes on Evidence/Draft. Veto during
  approval if you'd rather keep competences as a JSON blob on `referentiels`.
- **No** LLM calls, **no** HTTP routes, **no** Livewire components, **no**
  queue jobs, **no** sandbox/runner changes in this change.

## Capabilities

### New Capabilities
- `domain-model`: Eloquent models + migrations for the Mizan grading domain —
  Referentiel, Level, Competence, Brief, StudentRepo, Run, Evidence (Pass 1,
  blind, file+line), Draft (AI draft vs operator-finalized, default
  `à vérifier`), ProbeFlag (Pass 2, contextual). Hard rules R1, R3, R4 are
  enforced in the schema itself: separated Pass 1 / Pass 2 tables, a nullable
  operator-finalized column guarded by `finalized_at`, and an operator-only
  persona column hidden from serialization.

### Modified Capabilities
<!-- None — `runner-cli` is the only existing spec and its requirements do not change. -->

## Impact

- **Code**: New files only in `apps/web/`:
  - `database/migrations/` — 7 new migration files (some create multiple
    related tables in one file where they're co-dependent: referentiels+levels,
    competences, briefs, student_repos, runs, evidence, drafts, probe_flags).
  - `app/Models/` — 9 new model classes (`Referentiel`, `Level`, `Competence`,
    `Brief`, `StudentRepo`, `Run`, `Evidence`, `Draft`, `ProbeFlag`).
  - `database/factories/` — 9 new factories.
  - `tests/Feature/` and/or `tests/Unit/` — new test classes for migrations,
    relations, and hard-rule guarantees.
- **APIs**: None external. New internal Eloquent API used by later changes.
- **Dependencies**: None new. Uses Laravel 13 + Eloquent already in
  `apps/web/composer.json`. Tests run on the existing SQLite `:memory:` config
  in `phpunit.xml`.
- **Hard rules touched**:
  - **R1** (LLM never emits a final verdict; operator finalizes): ENFORCED IN
    SCHEMA. `drafts` has `ai_status` (the AI draft, defaults to
    `à vérifier`) and a SEPARATE nullable `operator_status` plus
    `finalized_at`. The `Draft` model exposes a `finalVerdict()` accessor that
    returns `operator_status` ONLY when `finalized_at` is non-null, else null —
    so an un-finalized draft can never be read as a final verdict by any
    caller. A test asserts `finalVerdict()` is null until finalized.
  - **R3** (two-pass separation, blind Pass 1 vs contextual Pass 2): ENFORCED
    IN SCHEMA. Pass 1 blind evidence lives in `evidence` (cites `file_path` +
    `line_number`, no student-identity columns). Pass 2 contextual flags live
    in a SEPARATE `probe_flags` table (`kind` ∈ {divergence, regression},
    `context_payload` JSON). The two share no merged `notes`/`blob` column. A
    test asserts no `student_repos` FK on `evidence` and that `probe_flags`
    has no file+line citation columns — proving the two passes are not
    cross-contaminated at the schema level.
  - **R4** (persona/level is operator's private tag, never student-facing,
    never in Pass 1): ENFORCED IN SCHEMA. The persona tag is a single column
    `student_repos.operator_persona` (nullable) on an operator-facing entity.
    The `StudentRepo` model marks it `$hidden` (never serialized) and the
    `evidence` (Pass 1) table has NO persona column and NO FK to
    `student_repos` — so persona cannot reach Pass 1 via the schema. A test
    asserts `StudentRepo::makeHidden`/serialization omits `operator_persona`
    and that `evidence` has no `operator_persona` / `student_repo_id` column.
  - **R2** (runner dumb and constant) — NOT touched; no runner changes.
  - **R5** (boring app) — respected: plain Eloquent, plain migrations, no
    clever computed columns, no triggers, no denormalized caches.
- **Sandbox/security boundary**: **Not touched.** No `apps/runner` edits, no
  Docker, no egress, no secrets. The student repo path stored in
  `student_repos.clone_path` is an operator-supplied local path under the
  existing v0 "trusted repos only on local Laragon host" constraint inherited
  from `runner-foundation-v0`; it is not mounted into any container by this
  change. Sandbox hardening remains deferred to `change/runner-sandbox`
  (requires human review).
