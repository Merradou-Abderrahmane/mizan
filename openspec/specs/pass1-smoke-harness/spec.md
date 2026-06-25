# pass1-smoke-harness Specification

## Purpose

Operational tooling that makes the full `intake → runner → pass1:grade` pipeline
exercisable from the shell against a real `glm-5.2` call on a v0 trusted repo,
before the operator control panel UI exists. The capability wraps two existing
capabilities without changing their behavior:

- **`runner-cli`** is invoked as-is via `RepoIntakeService::intake()` (R2 — the
  `repo:intake` command is a thin caller; it constructs no models, makes no
  transactions, and is verified not to modify any tracked `apps/runner/` file).
- **`domain-model`** is populated by `SystemSeeder` with one realistic
  référentiel graph (1 référentiel / 3 levels / 11 competences / 33 criteria /
  1 ThreadForge brief / 5 brief_competence pivots at target levels), idempotent
  via `firstOrCreate` + `syncWithoutDetaching`.

The capability respects R4 (the seeder does NOT seed `operator_persona` on any
row — identity stays operator-private and only enters the system at runtime via
`repo:intake`) and R5 (the command is a 1-method wrapper; the seeder is plain
`firstOrCreate` rows; no clever heuristics). R1 and R3 are not touched.

**Scope note:** `storage/test-repos/` is gitignored so operator-owned student
clones never land in a commit. The capability deliberately ships NO UI, NO
`apps/runner/` modification, NO Docker, NO egress, and NO new secret; the first
LIVE `glm-5.2` call happens only when the operator runs `pass1:grade` after
this capability is in place AND after the operator confirms `glm-5.2`
zero-retention (the go-live gate, recorded in the handoff log; not a code
change here).

**Fixture caveat:** the seeder's criterion description texts are inspired by
the ThreadForge brief's "Critères de performance" and are intended as
smoke-test fixtures — they make a live `glm-5.2` grade meaningful as a
plumbing proof (does the prompt + parser + hedging + citation enforcement
hold against a real LLM response?), NOT as a real evaluation. The operator
MUST replace `SystemSeeder`'s criterion descriptions with the real critères
d'évaluation from the authoritative référentiel before any real evaluation.
The seeder lives under `database/seeders/` (Laravel's dev-only location) so
swapping has no schema consequence.
## Requirements
### Requirement: `repo:intake` artisan command

The system SHALL expose `php artisan repo:intake {source} {brief}` as a thin entry point around the existing `RepoIntakeService::intake()` (Change C). `{source}` is forwarded verbatim to the service — it accepts either a local filesystem path or a `git`/`http(s)`/`file://` URL, with no command-level normalization. The command SHALL NOT contain domain logic (R5): it constructs no models, makes no transactions, and does not modify `apps/runner/` (R2). On success it prints exactly `Run {id} created (status: {status}).` to stdout where `{id}` is the new `Run` primary key and `{status}` is the service-returned `Run.status`. On failure it prints a one-line message to stderr and exits 1.

#### Scenario: Intake a local path that passes runner checks
- **WHEN** the operator runs `php artisan repo:intake /var/repos/forgecore 1` and the path exists and the brief id 1 exists and the runner's six structural checks all return `pass`
- **THEN** a `Run` row is persisted by `RepoIntakeService::intake()` with `status` reflecting the runner report and `runner_report_json` containing the six `CheckResult` objects and `clone_path` on `StudentRepo` equal to `/var/repos/forgecore`, and the command prints `Run {id} created (status: {status}).` to stdout and exits 0

#### Scenario: Intake with a non-existent brief
- **WHEN** the operator runs `php artisan repo:intake /var/repos/forgecore 9999` and no `Brief` with id 9999 exists
- **THEN** the command prints `Brief not found: 9999` to stderr and exits 1, and no `Run` or `StudentRepo` rows are created

#### Scenario: Intake with a path that does not exist
- **WHEN** the operator runs `php artisan repo:intake /no/such/path 1` and the local path does not exist
- **THEN** the runner subprocess reports a `composer_install` failure (the runner's own behavior, NOT a command-level guard), the service persists a `Run` with `status` reflecting the runner result, and the command prints `Run {id} created (status: {status}).` and exits 0 — the command does not pre-validate path existence (the runner is the single source of structural truth, R2)

#### Scenario: Intake with a malformed brief id
- **WHEN** the operator runs `php artisan repo:intake /var/repos/forgecore abc` (non-numeric brief id)
- **THEN** the command prints `Brief id must be an integer.` to stderr and exits 1 with no service invocation

#### Scenario: URL source works but its clone is deleted by the service's `finally`
- **WHEN** the operator runs `php artisan repo:intake https://github.com/example/repo.git 1`
- **THEN** the service clones to a temp directory under `storage/runner-clones/`, runs the runner against the clone, persists the `Run` with `clone_path` set to the original URL (per existing `RepoIntakeService` behavior), and the command prints the success line — the operator is expected to know that for any subsequent `pass1:grade` the digest needs a readable local path, so URLs are valid only for runs that never call Pass 1

#### Scenario: Command does not modify the runner
- **WHEN** the command is invoked against any source
- **THEN** no tracked file under `apps/runner/` is modified by the invocation (a repo-level check `git status -- apps/runner` returns empty after the run)

### Requirement: Singleton idempotent seeder for the smoke-test domain

The system SHALL ship a `SystemSeeder` invoked from `DatabaseSeeder::run()` that builds exactly: one `Référentiel` (matched by `title` for idempotency — the `referentiels` table has no `code` column), three `Levels` (`N1` imiter / `N2` adapter / `N3` transposer, codes on the existing `levels.code` column), eleven `Competences` (five `kind='technique'` codes `T-C5`, `T-C6`, `T-C3`, `T-C7`, `T-C9`; six `kind='transversale'` codes `TR-C1`, `TR-C2`, `TR-C3`, `TR-C4`, `TR-C5`, `TR-C1b`, codes on the existing `competences.code` column), thirty-three `Criteria` (one per `(competence, level)` pair, code `<competence_code>-<level_code>` on the existing `criteria.code` column), one `Brief` (matched by `(referentiel_id, title)` for idempotency — the `briefs` table has no `code` column), and five `brief_competence` pivot rows attaching the five technique competences to the brief with the target `level_id` per competence. Each row SHALL be built via `firstOrCreate` keyed on stable matching attributes (or `syncWithoutDetaching` for the pivot, keyed on `(brief_id, competence_id)`) so that `php artisan migrate:fresh --seed` and any re-run is repeatable without constraint violations or duplicate rows (R5).

The seeder SHALL NOT seed any `StudentRepo` and SHALL NOT set `operator_persona` on any row (R4 — persona is the operator's private tag and only enters the system at runtime via `repo:intake`). The seeder SHALL NOT seed any `Run`, `Evidence`, `Draft`, or `Pass1CompetenceResult` (these are produced by `repo:intake` and `pass1:grade`, not by static data).

Target `level_id` per technique competence in the seeder, fixed:

| Competence code | Label | Target level |
|---|---|---|
| `T-C7` | Concevoir et mettre en place une base de données | N2 (adapter) |
| `T-C6` | Développer des composants d'accès aux données | N2 (adapter) |
| `T-C3` | Développer des composants métier | N2 (adapter) |
| `T-C5` | Développer la partie back-end d'une interface utilisateur web | N3 (transposer) |
| `T-C9` | Préparer et exécuter les plans de tests | N1 (imiter) |

#### Scenario: Seeder creates the full référentiel graph
- **WHEN** the operator runs `php artisan migrate:fresh --seed` against an empty schema
- **THEN** the DB contains exactly 1 `Référentiel` row, 3 `Level` rows, 11 `Competence` rows (5 with `kind='technique'`, 6 with `kind='transversale'`), 33 `Criterion` rows (one per `(competence, level)` pair), 1 `Brief` row, and 5 rows in `brief_competence` — and zero `StudentRepo`, `Run`, `Evidence`, `Draft`, or `Pass1CompetenceResult` rows

#### Scenario: Seeder is idempotent across re-runs
- **WHEN** the operator runs `php artisan migrate:fresh --seed` twice in succession
- **THEN** the second run completes without constraint violations and the row counts are unchanged from the first run (no duplicate rows; `firstOrCreate` and `syncWithoutDetaching` ensure stable ids)

#### Scenario: All technique competences have a brief target level
- **WHEN** the seeder completes
- **THEN** every `Competence` where `kind='technique'` has exactly one row in `brief_competence` with a non-null `level_id` resolving to a `Level` in {N1, N2, N3}, and no `Competence` where `kind='transversale'` has a `brief_competence` row (Pass 1 grades technique only; transversales never enter a prompt)

#### Scenario: Seeder does not seed operator persona
- **WHEN** the seeder completes
- **THEN** no `StudentRepo` row exists, and no row in any table contains a non-null `operator_persona` value (an explicit guard — the smoke test does not couple test data to operator identity, R4)

#### Scenario: Criteria texts reflect the ThreadForge brief's performance families
- **WHEN** the seeder completes
- **THEN** each `Criterion.description` is a non-empty string, and the criteria for {`T-C5`, `T-C6`, `T-C3`, `T-C7`, `T-C9`} at {N1, N2, N3} collectively reference at least one of: Sanctum / API Resources / Form Requests / N+1 / Queue / 202 Accepted / structured output / Eloquent JSON cast / function-calling tool / conversation memory / atomic commits / Scribe — i.e., the criterion texts are inspired by the ThreadForge brief's `Critères de performance` rather than invented from nothing

### Requirement: `storage/test-repos/` is gitignored

The repository `.gitignore` SHALL include an entry for `storage/test-repos/` (relative to `apps/web/`) so student clones created by the operator for smoke testing are never accidentally committed. This is a one-line change to a single `.gitignore` file; no code or behavior is affected.

#### Scenario: A clone placed under `storage/test-repos/` is ignored by git
- **WHEN** the operator creates `storage/test-repos/ForgeCoreApi/` (e.g. via `git clone --depth 1 <url> <path>`) and runs `git status` from `apps/web/`
- **THEN** the clone directory does not appear in `git status --porcelain` output (the entry matches it), so a subsequent `git add .` cannot stage it

#### Scenario: Other `storage/` content remains tracked as before
- **WHEN** the operator adds the new `.gitignore` entry and runs `git status`
- **THEN** no previously-tracked file under `storage/` becomes newly ignored — the new entry is scoped to `storage/test-repos/` only, leaving existing `storage/logs/`, `storage/app/` content rules intact

