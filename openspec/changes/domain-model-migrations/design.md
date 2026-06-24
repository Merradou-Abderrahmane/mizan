## Context

`apps/web` is a fresh Laravel 13 skeleton (default `users`, `cache`, `jobs`
migrations only; `User` model + factory; no domain models). The runner CLI
from `runner-foundation-v0` is merged on `main` and emits a JSON report, but
`apps/web` has nowhere to persist a run, its evidence, the AI's draft, or the
operator's finalized verdict. This change adds the domain schema that every
subsequent change (LLM Pass 1, Pass 2, UI, finalize flow) will build on.

The hard rules R1, R3, R4 govern *data shape*, not just app logic. Encoding
them in the schema now means a later change cannot accidentally merge Pass 1
and Pass 2, expose a persona to student-facing output, or read an un-finalized
draft as a verdict — without also changing the schema (which is a visible,
reviewable event).

`openspec/config.yaml` declares the runner executes untrusted code and "treat
as hostile." This change does NOT touch the runner, the sandbox, or egress.

## Goals / Non-Goals

**Goals:**
- Migrations for 9 domain tables: `referentiels`, `levels`, `competences`,
  `briefs`, `student_repos`, `runs`, `evidence`, `drafts`, `probe_flags`.
- Eloquent models with correctly typed relations for all 9 entities.
- Model factories for all 9 entities so tests can build graph fixtures.
- R1 enforced in schema: `drafts` separates `ai_status` (default
  `à vérifier`) from nullable `operator_status` + `finalized_at`; a
  `finalVerdict()` accessor returns null until finalized.
- R3 enforced in schema: `evidence` (Pass 1, blind, file+line, no
  student-identity FK) is a separate table from `probe_flags` (Pass 2,
  contextual, `kind` ∈ {divergence, regression}, JSON context payload). No
  merged notes blob.
- R4 enforced in schema: `student_repos.operator_persona` is `$hidden` on the
  model; `evidence` has no persona column and no FK to `student_repos`.
- Tests asserting the above guarantees hold at the schema/model layer.

**Non-Goals:**
- No LLM calls, no Pass 1 / Pass 2 *logic*, no grading, no verdict computation.
- No HTTP routes, no Livewire components, no UI, no queue jobs.
- No `apps/runner` changes, no sandbox/egress/secrets changes.
- No seeders with real data (factories only; seeding is a later change).
- No soft-deletes (R5: boring app; the operator is a single user, deletion is
  explicit and rare).

## Decisions

### D1. `competences` is a first-class entity, not a JSON blob on `referentiels`
**Rationale:** R3 mandates per-competence grading. Evidence and Draft both need
to cite a competence. A normalized `competences` table (FK to `referentiels`,
optional FK to `levels`) is cleaner and more "boring" (R5) than free-text
competence codes duplicated across `evidence` and `drafts`. It also lets the
operator edit the référentiel once and have all runs reference the updated
competence set.
**Alternatives considered:**
- JSON array of competence codes on `referentiels`: rejected — denormalized,
  hard to index, hard to join for "show me all evidence for competence X".
- No competence entity, just a string column on Evidence/Draft: rejected — no
  referential integrity; typos produce phantom competences.

### D2. `drafts` has two status columns + a finalized_at timestamp, not one
**Rationale:** R1 says the LLM never emits a final verdict. If `drafts` had a
single `status` column, any code reading it could not tell "AI draft" from
"operator final." Two columns (`ai_status`, `operator_status`) + `finalized_at`
make the separation structural. The `finalVerdict()` accessor returns
`operator_status` only when `finalized_at` is non-null, else null — so an
un-finalized draft is provably unreadable as a verdict.
**Alternatives considered:**
- Single `status` + a boolean `is_final`: rejected — the boolean is easy to
  flip by accident; a timestamp is auditable.
- A separate `verdicts` table 1:1 with `drafts`: rejected — premature
  normalization; the operator finalizes in-place, a separate table adds a join
  for no benefit at this stage.

### D3. `ai_status` defaults to `à vérifier`, not `null`
**Rationale:** R1 says "anything needing oral confirmation defaults to à
vérifier, never valide." A DB-level `DEFAULT 'à_verifier'` means even a raw
INSERT with no application code produces a safe draft, never a phantom
`valide`. The value is stored as `à_verifier` (UTF-8) to match the
operator-facing vocabulary exactly; the model casts to/from an enum.
**Alternatives considered:**
- Store English `a_verifier` and translate in UI: rejected — the operator
  reads French; storing the French value avoids a translation layer for a
  3-value enum.
- Default to `null` and let app code set it: rejected — a missed app-code path
  would produce null, which is ambiguous (is that "not yet assessed" or
  "bug"?).

### D4. `evidence` has no FK to `student_repos`; `runs` is the bridge
**Rationale:** R3 says Pass 1 is blind — zero student identity in context. If
`evidence` had a `student_repo_id` column, any query joining evidence to
student_repos would leak identity into Pass 1. Instead, `evidence` belongs to
`runs`, and `runs` belongs to `student_repos`. The application can join
through `runs` for operator-facing views, but Pass 1 code that queries
`evidence` by `run_id` never touches `student_repos`. A test asserts the
`evidence` table has no `student_repo_id` column.
**Alternatives considered:**
- `evidence` has `student_repo_id` but app code promises not to use it in
  Pass 1: rejected — R3 is a hard rule; relying on app-code discipline is
  weaker than schema-level separation.

### D5. `probe_flags` is a separate table from `evidence`, not a `kind` column on evidence
**Rationale:** R3 says Pass 2 probe flags are contextual (use student
level/history) and never re-grade. They have different fields from Pass 1
evidence (a `kind` ∈ {divergence, regression}, a JSON `context_payload` for
the student-history context that triggered the flag, no file+line citation).
Merging them into one `evidence` table with a `kind` column would require
nullable file+line and nullable context_payload, and would let a query
accidentally pull Pass 2 flags into a Pass 1 evidence list. Separate tables
make the passes structurally distinct.
**Alternatives considered:**
- Single `findings` table with `pass` column: rejected — see R3 rationale
  above; nullable columns for both pass shapes is a schema smell and a
  cross-contamination risk.

### D6. `student_repos.operator_persona` is nullable, `$hidden`, on an operator-facing entity
**Rationale:** R4 says persona/level is the operator's private tag, never in
student-facing output, never in Pass 1. The column lives on `student_repos`
(an operator-facing entity — students don't see the repo registry). The model
marks it `$hidden` so `toArray()`/JSON serialization omits it. `evidence`
(Pass 1) has no persona column and no FK to `student_repos` (D4), so persona
cannot reach Pass 1 via the schema. A test asserts serialization omits
`operator_persona` and that `evidence` has no such column.
**Alternatives considered:**
- `operator_persona` on `runs`: rejected — a student repo can have multiple
  runs; the persona is a property of the student, not the run. Also `runs` is
  closer to the grading pipeline, increasing exposure risk.
- A separate `personas` table: rejected — premature; a single nullable column
  is boring (R5) and sufficient until the operator needs multi-tag.

### D7. Migration file grouping: co-dependent tables share one file
**Rationale:** Laravel migrations are timestamp-prefixed and run in order.
Grouping `referentiels` + `levels` in one file, `competences` in another,
`briefs` in another, `student_repos` in another, `runs` + `evidence` +
`drafts` + `probe_flags` in one file (since they all FK to `runs`) keeps the
migration count manageable and the FK ordering correct. Total: 5 migration
files. This is boring (R5) and keeps `php artisan migrate` deterministic.
**Alternatives considered:**
- One file per table (9 files): rejected — more files, more ordering
  ceremony, no benefit for a fresh schema with no data to migrate.

### D8. No soft-deletes, no polymorphic relations
**Rationale:** R5 (boring app). Soft-deletes add a `deleted_at` column and
query-scope complexity for a single-operator tool where deletion is explicit
and rare. Polymorphic relations (`morphTo`/`morphMany`) would let `evidence`
point to any model, but we know exactly what evidence points to (`runs`); a
direct FK is clearer and indexable.

## Sandbox / Security Impact

**Stated explicitly per `openspec/config.yaml` rule ("design: Note the
sandbox/security impact explicitly, even if 'none'").**

This change's security impact: **none.**

- No `apps/runner` edits, no Docker, no egress, no secrets.
- `student_repos.clone_path` stores an operator-supplied local filesystem
  path under the existing v0 "trusted repos only on local Laragon host"
  constraint inherited from `runner-foundation-v0`. It is not mounted into any
  container by this change. The column is a plain string; it does not cause
  any filesystem access.
- No untrusted code is executed by this change (migrations + models only).
- Sandbox hardening remains deferred to `change/runner-sandbox`, which
  requires human review per `openspec/config.yaml`.

## Risks / Trade-offs

- **[Risk] `competences` is an inferred entity not in the user's original
  list.** If the operator prefers a JSON blob on `referentiels`, this is
  extra schema to undo.
  → **Mitigation**: Flagged in `proposal.md` for approval. The entity is
  boring (3 columns: id, referentiel_id, code) and easy to drop if vetoed.
- **[Risk] Storing `à_verifier` as UTF-8 in MySQL** could cause collation
  issues if the DB charset is not `utf8mb4`.
  → **Mitigation**: Laravel 13 default charset is `utf8mb4` / `unicode_520_ci`
  or equivalent; the migration does not override the default. A test asserts
  the value round-trips through the DB.
- **[Trade-off] No soft-deletes now** = simpler schema now, plus an explicit
  obligation to add them if the operator ever wants "undo" on a deleted run.
  Low risk for a single-operator tool.
- **[Trade-off] `evidence` has no `student_repo_id`** = Pass 1 is provably
  blind at the schema level, BUT operator-facing UI that wants "all evidence
  for student X" must join through `runs`. This is intentional (R3) and
  documented in the model's docblock.

## Migration Plan

- New, additive-only migrations; no existing behavior to migrate. The default
  Laravel `users`/`cache`/`jobs` migrations are untouched.
- Landing order: run `php artisan migrate` on the local Laragon MySQL. Tests
  run on SQLite `:memory:` per `phpunit.xml`.
- Rollback: `php artisan migrate:rollback` drops all 9 tables; nothing else
  depends on them yet.

## Open Questions

- OQ1. Should `levels` be a separate table or an enum on `competences`? The
  référentiel has "levels" (the user listed "Referentiel(+Level)"), which
  suggests a `levels` table FK'd to `referentiels`, with `competences`
  optionally FK'd to a level. Current decision: separate `levels` table —
  the operator may want to define levels once per référentiel and reuse
  across competences. Revisit at task time if the operator prefers a simpler
  enum.
- OQ2. Should `drafts` store the AI's raw LLM JSON response alongside the
  parsed `ai_status`? Probably yes (for auditability), but it's not required
  by R1 and adds a `json` column. Current decision: include a nullable
  `ai_raw_json` column — it's boring and useful for debugging, and doesn't
  affect the hard rules. Veto if you want the table leaner.
- OQ3. Should `runs` store the full runner JSON report blob, or just FK to
  evidence rows? Current decision: store `runner_report_json` (nullable) on
  `runs` AND populate `evidence` rows. The blob is the raw input; the
  evidence rows are the structured, per-competence-citable form. Veto if you
  want the blob in a separate `run_artifacts` table.
