## ADDED Requirements

### Requirement: RepoIntakeService entry contract
The system SHALL provide a `RepoIntakeService` class in `apps/web/app/Services/`
with a single public method `intake` that takes:
- `string $source` — a Git URL (starting with `http://`, `https://`, or
  `git@`) or a local filesystem path (anything else);
- `int $briefId` — the id of an existing `Brief`;
- `?int $studentRepoId = null` — optional id of an existing `StudentRepo` to
  reuse (a new one is created if null);
- `?string $operatorPersona = null` — optional operator-private tag (R4),
  stored on `StudentRepo` if a new one is created, ignored if `$studentRepoId`
  is provided;
- `?string $name = null` — optional display name for a new `StudentRepo`
  (auto-derived from the source basename if null).

The method SHALL return the persisted `Run` model. The method SHALL throw on
unrecoverable errors (clone failure, runner crash, invalid JSON, missing
Brief). The operator's persona SHALL NEVER be passed to the runner subprocess
(R2 — runner env allowlist is empty; R4 — persona never enters Pass 1). The
service SHALL NOT create any `Evidence` rows — the runner's structural checks
are input artifacts, not per-competence R3 findings; they live in
`runner_report_json` on the `Run` (see D5 in design.md).

#### Scenario: Happy path with a Git URL
- **GIVEN** a valid public Git URL and an existing `Brief`
- **WHEN** `RepoIntakeService::intake($url, $briefId)` is called
- **THEN** the repository SHALL be shallow-cloned to a temp directory under
  `storage/runner-clones/`
- **AND** the runner SHALL be invoked with the clone path
- **AND** a `StudentRepo` SHALL be created with the URL as `clone_path`
- **AND** a `Run` SHALL be created linked to the `StudentRepo` and `Brief`
- **AND** `Run.runner_report_json` SHALL equal the full decoded runner report
- **AND** NO `Evidence` rows SHALL be created for that `Run`
- **AND** the temp clone directory SHALL NOT exist after the method returns
- **AND** the `Run` model SHALL be returned

#### Scenario: Happy path with a local path
- **GIVEN** a local filesystem path to an existing repo directory and an
  existing `Brief`
- **WHEN** `RepoIntakeService::intake($path, $briefId)` is called
- **THEN** NO clone SHALL be performed (the path is used as-is)
- **AND** a `StudentRepo` SHALL be created with the path as `clone_path`
- **AND** the runner SHALL be invoked with the local path
- **AND** the local path SHALL NOT be deleted after the method returns
  (operator-owned)

#### Scenario: Reuse existing StudentRepo
- **GIVEN** an existing `StudentRepo` id and an existing `Brief`
- **WHEN** `RepoIntakeService::intake($source, $briefId, $studentRepoId)` is
  called
- **THEN** a new `Run` SHALL be created linked to the existing `StudentRepo`
- **AND** NO new `StudentRepo` SHALL be created
- **AND** the `operatorPersona` argument SHALL be ignored (existing record's
  persona stands)

#### Scenario: Missing Brief throws
- **GIVEN** a non-existent `Brief` id
- **WHEN** `RepoIntakeService::intake($source, 99999)` is called
- **THEN** the method SHALL throw an exception (e.g.,
  `ModelNotFoundException` or a domain-specific `BriefNotFoundException`)

#### Scenario: Persona never passed to runner subprocess (R2, R4)
- **GIVEN** `RepoIntakeService::intake($url, $briefId, null, "advanced")` is
  called
- **WHEN** the runner subprocess is invoked
- **THEN** the subprocess environment SHALL NOT contain `operator_persona` or
  any persona-derived value
- **AND** the `StudentRepo.operator_persona` SHALL equal `"advanced"`
- **AND** no `Evidence` row SHALL contain the persona value (and no
  `Evidence` rows are created at all)

#### Scenario: No Evidence rows created (R3)
- **GIVEN** `RepoIntakeService::intake($url, $briefId)` completes successfully
  and the runner report has 6 check entries
- **WHEN** the service has persisted the `Run`
- **THEN** 0 `Evidence` rows SHALL exist for that `Run`
- **AND** `Run.runner_report_json` SHALL contain the full report with its 6
  check entries

---

### Requirement: Shallow clone to temp directory with guaranteed cleanup
The service SHALL clone Git-URL sources to a unique subdirectory under
`storage/runner-clones/` using `git clone --depth 1`. The subdirectory SHALL be
named with a UUID to avoid collisions. The service SHALL delete the clone
directory in a `finally` block (always, even on runner failure or exception).
Local-path sources SHALL NOT be cloned or deleted.

#### Scenario: Clone directory is cleaned up on success
- **GIVEN** `RepoIntakeService::intake($url, $briefId)` completes successfully
- **WHEN** the method returns
- **THEN** the temp clone directory SHALL NOT exist on disk

#### Scenario: Clone directory is cleaned up on runner failure
- **GIVEN** the runner subprocess exits non-zero and produces an `error` report
- **WHEN** the method returns
- **THEN** the temp clone directory SHALL NOT exist on disk
- **AND** a `Run` with `status = "error"` SHALL be persisted

#### Scenario: Clone directory is cleaned up on exception
- **GIVEN** the runner subprocess crashes and stdout is not valid JSON
- **WHEN** the method throws
- **THEN** the temp clone directory SHALL NOT exist on disk (the `finally`
  block ran)

#### Scenario: Local path is not deleted
- **GIVEN** `RepoIntakeService::intake($localPath, $briefId)` completes
- **WHEN** the method returns
- **THEN** the local path SHALL still exist on disk (operator-owned)

---

### Requirement: Runner invoked unchanged via Symfony Process (R2)
The service SHALL invoke the runner CLI at `apps/runner/bin/runner` (resolved
relative to the app base path) via Symfony Process, passing the resolved repo
path as the sole positional argument. The service SHALL NOT modify any file in
`apps/runner/`. The service SHALL NOT pass environment variables to the
subprocess beyond the inherited host environment (the runner's allowlist is
empty per its spec). The service SHALL capture stdout (the JSON report) and
stderr (logs, ignored). The service SHALL NOT pass `--composer` or
`--workdir` flags (v0 — the runner uses host defaults).

#### Scenario: Runner called with clone path as positional arg
- **GIVEN** `RepoIntakeService::intake($url, $briefId)` is called
- **WHEN** the runner subprocess is invoked
- **THEN** the command SHALL be `php <base_path>/apps/runner/bin/runner
  <clonePath>`
- **AND** no `apps/runner/` file SHALL be modified by this change

#### Scenario: No env vars passed to runner (R2)
- **WHEN** the runner subprocess is invoked
- **THEN** no custom environment variables SHALL be set by the service (it
  inherits the host env as-is; the runner's own allowlist filters what it
  reads)

---

### Requirement: Report parsing and persistence (Run only, no Evidence)
The service SHALL parse the runner's stdout as JSON. On a valid report:
- Create or reuse a `StudentRepo` (per the entry contract).
- Create a `Run` with:
  - `student_repo_id` and `brief_id` from the resolved entities;
  - `status` ← report top-level `status` (`pass` / `fail` / `error`);
  - `runner_report_json` ← the full decoded report array;
  - `started_at` ← report `started_at`;
  - `ended_at` ← report `ended_at`.
The service SHALL NOT create any `Evidence` rows — the runner's structural
checks are input artifacts, not per-competence R3 findings (see D5 in
design.md). The full report is preserved in `runner_report_json` on the `Run`.
The service SHALL wrap the persistence in a DB transaction so a parse error
mid-way rolls back the Run.

On invalid JSON (runner crash): the service SHALL create a `Run` with
`status = "error"`, `runner_report_json = ["raw_stdout" => "<raw>"]`, no
`Evidence` rows, and throw (the caller decides how to surface the error).

#### Scenario: Valid report — Run persisted, no Evidence rows
- **GIVEN** the runner produces a valid report with `status: "fail"` and 6
  check entries (2 pass, 3 fail, 1 skip)
- **WHEN** the service parses and persists
- **THEN** a `Run` SHALL exist with `status = "fail"`
- **AND** `Run.runner_report_json` SHALL equal the full decoded report
  (including all 6 check entries)
- **AND** 0 `Evidence` rows SHALL exist for that `Run`

#### Scenario: Invalid JSON — Run persisted with error, no Evidence rows
- **GIVEN** the runner crashes and stdout is `"not json"`
- **WHEN** the service attempts to parse and persist
- **THEN** a `Run` with `status = "error"` SHALL be persisted (with
  `runner_report_json` containing `raw_stdout`)
- **AND** NO `Evidence` rows SHALL exist for that `Run`
- **AND** the method SHALL throw

#### Scenario: Error status — Run persisted with error
- **GIVEN** the runner produces a valid report with `status: "error"` and an
  empty `checks` array (e.g., bad repo path)
- **WHEN** the service parses and persists
- **THEN** a `Run` SHALL exist with `status = "error"`
- **AND** 0 `Evidence` rows SHALL exist for that `Run`

---

### Requirement: StudentRepo name auto-derivation
When `$name` is null and `$studentRepoId` is null, the service SHALL derive
the `StudentRepo.name` from the source basename: for a URL, the repo name
without `.git` suffix (e.g., `https://github.com/user/repo.git` → `repo`); for
a local path, the trailing directory name (e.g., `/path/to/my-repo` →
`my-repo`). When `$name` is provided, it SHALL be used as-is.

#### Scenario: Name derived from URL
- **GIVEN** `RepoIntakeService::intake("https://github.com/u/repo.git", $briefId)`
- **WHEN** a new `StudentRepo` is created
- **THEN** `StudentRepo.name` SHALL equal `"repo"`

#### Scenario: Name derived from local path
- **GIVEN** `RepoIntakeService::intake("/path/to/my-repo", $briefId)`
- **WHEN** a new `StudentRepo` is created
- **THEN** `StudentRepo.name` SHALL equal `"my-repo"`

#### Scenario: Explicit name overrides derivation
- **GIVEN** `RepoIntakeService::intake($url, $briefId, null, null, "Custom")`
- **WHEN** a new `StudentRepo` is created
- **THEN** `StudentRepo.name` SHALL equal `"Custom"`