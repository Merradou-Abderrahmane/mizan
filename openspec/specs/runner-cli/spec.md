# runner-cli Specification

## Purpose
The runner CLI is Mizan's deterministic, stack-specific structural-check worker:
it takes the path to a cloned student repo, runs a fixed set of PHP/Laravel
checks, and emits an evidence-backed JSON report on stdout. Per Hard Rule R2 it
stays dumb and constant — the checks do not vary per brief — and per R1 it emits
only evidence, never a grading verdict.

## Requirements
### Requirement: Runner CLI entry contract
The runner SHALL be a PHP CLI at `apps/runner/bin/runner` that takes exactly one
positional argument — the absolute or relative path to a cloned student repo —
and writes the structural-check report as a single JSON object to stdout.
Nothing else SHALL be written to stdout. All human-facing logs SHALL go to
stderr.

The runner SHALL accept an optional `--composer=<path>` flag overriding the
`composer` binary path. The runner SHALL NOT read any environment variable not
on an explicit allowlist (initially empty) — keeping it containerizable without
API change.

The runner SHALL be stateless between invocations: it SHALL NOT persist any
file, cache, or lock outside `<repoPath>/storage/` (or an explicit
`--workdir=<path>`).

#### Scenario: Happy path — valid repo path produces JSON on stdout
- **GIVEN** a fixture repo at `tests/fixtures/valid_repo` that passes every check
- **WHEN** the operator runs `php bin/runner tests/fixtures/valid_repo`
- **THEN** stdout SHALL contain exactly one JSON object conforming to the
  Report schema below
- **AND** the process SHALL exit with code `0`

#### Scenario: Missing repo path argument
- **GIVEN** no positional argument is provided
- **WHEN** the operator runs `php bin/runner`
- **THEN** the runner SHALL print a usage message to stderr
- **AND** exit with code `2`
- **AND** write nothing to stdout

#### Scenario: Repo path does not exist
- **GIVEN** a path that does not resolve to a directory
- **WHEN** the operator runs `php bin/runner /no/such/dir`
- **THEN** the runner SHALL emit a JSON object to stdout with `status: "error"`
  and `error_class: "repo_path_not_found"`
- **AND** exit with code `1`

#### Scenario: Stdout streams only JSON, logs stream only to stderr
- **GIVEN** any invocation that completes
- **WHEN** stdout is captured
- **THEN** stdout SHALL parse as a single JSON object
- **AND** SHALL contain no non-JSON bytes before or after the object

### Requirement: Report JSON schema
The runner SHALL emit a report object matching EXACTLY this JSON shape. No
additional top-level keys SHALL be present. The `checks` array SHALL contain
exactly one entry per check defined in this spec, in the fixed order:
`composer_install`, `app_boots`, `migrations_run`, `readme_real`, `env_not_tracked`,
`git_history_real`.

```json
{
  "schema_version": 1,
  "status": "pass" | "fail" | "error",
  "runner_version": "0.1.0",
  "repo_path": "<repoPath as passed by the operator>",
  "started_at": "ISO-8601 UTC",
  "ended_at": "ISO-8601 UTC",
  "duration_ms": <integer milliseconds>,
  "checks": [
    {
      "id": "composer_install" | "app_boots" | "migrations_run"
            | "readme_real" | "env_not_tracked" | "git_history_real",
      "status": "pass" | "fail" | "skip",
      "duration_ms": <integer>,
      "evidence": [
        {
          "file": "<relative path from repo root, or null>",
          "line": <1-based line number, or null>,
          "excerpt": "<string, max 500 chars, or null>",
          "kind": "stdout" | "stderr" | "git" | "filesystem" | "command"
        }
      ],
      "error_class": "<machine code, or null>",
      "message": "<short human string, or null>"
    }
  ]
}
```

Semantics:
- Top-level `status`:
  - `"pass"` — all checks have status `pass`.
  - `"fail"` — at least one check has status `fail`, none has `error`.
  - `"error"` — the runner could not run at all (bad repo path, internal
    exception). In this state `checks` MAY be an empty array.
- Per-check `status`:
  - `"pass"` — the condition the check verifies holds.
  - `"fail"` — the condition does not hold, with evidence cited.
  - `"skip"` — the check could not run for an environmental reason (e.g.,
    missing SQLite extension), distinct from a student fail. `error_class`
    SHALL be set; `evidence` MAY be empty.
- `evidence[].file` and `evidence[].line` SHALL be `null` when not applicable
  (e.g., command-output checks), and SHALL be set when the check makes a
  file-level claim (R3-grade citation readiness).
- `runner_version` SHALL be a SemVer string hardcoded in the runner; changing
  it is a breaking contract change requiring a new change proposal.

#### Scenario: All checks pass — top-level status is pass
- **GIVEN** the `valid_repo` fixture passes every check
- **WHEN** the report is parsed
- **THEN** `status` SHALL equal `"pass"`
- **AND** every entry in `checks` SHALL have `status: "pass"`
- **AND** `checks` SHALL have exactly 6 elements in the fixed order

#### Scenario: One check fails — top-level status is fail
- **GIVEN** a fixture where the README is the Laravel default stub
- **WHEN** the report is parsed
- **THEN** `status` SHALL equal `"fail"`
- **AND** the `readme_real` check entry SHALL have `status: "fail"`
- **AND** that entry's `evidence` SHALL include a `file: "README.md"`
- **AND** all other check entries SHALL have `status: "pass"`

#### Scenario: A check is skipped for environmental reasons
- **GIVEN** a host where the `pdo_sqlite` extension is absent
- **WHEN** the report is parsed
- **THEN** the `migrations_run` entry SHALL have `status: "skip"`
- **AND** `error_class` SHALL equal `"env_missing_sqlite"`
- **AND** top-level `status` SHALL equal `"fail"` (a skipped check is not a pass)

#### Scenario: Runner cannot start — top-level error status
- **GIVEN** a repo path that points to a file, not a directory
- **WHEN** the report is parsed
- **THEN** `status` SHALL equal `"error"`
- **AND** `error_class` SHALL equal `"repo_path_not_found"`
- **AND** `checks` SHALL be an empty array

### Requirement: Composer install check (`composer_install`)
The runner SHALL run `composer install --no-interaction --ignore-platform-reqs`
inside `<repoPath>` using the resolved `composer` binary (from PATH or
`--composer=<path>`). The runner SHALL NOT write to any host-global Composer
home or cache outside what Composer's own defaults produce for that project.

The check's result:
- `pass` — Composer exits `0`.
- `fail` — Composer exits non-zero. `evidence` SHALL include a `kind: "stderr"`
  excerpt of the relevant Composer output.
- `skip` — SHALL NOT happen (no environmental skip defined for this check).

#### Scenario: Dependencies install cleanly
- **GIVEN** a fixture repo with a valid `composer.json` and resolvable deps
- **WHEN** the `composer_install` check runs
- **THEN** its `status` SHALL be `"pass"` and `evidence` SHALL be non-empty with
  a `kind: "stdout"` excerpt

#### Scenario: Dependencies fail to install
- **GIVEN** a fixture repo whose `composer.json` requires a non-existent
  package version
- **WHEN** the `composer_install` check runs
- **THEN** its `status` SHALL be `"fail"`
- **AND** `evidence` SHALL include a `kind: "stderr"` excerpt of Composer's
  error output

### Requirement: Application boots check (`app_boots`)
The runner SHALL invoke `php artisan --version` inside `<repoPath>` after
`composer install` has run. The check:
- `pass` — the command exits `0` and prints a Laravel version string.
- `fail` — non-zero exit or no recognizable Laravel version in stdout.
  `evidence` SHALL cite the failing output.
- `skip` — SHALL NOT happen.

#### Scenario: App boots
- **GIVEN** a fixture Laravel app whose bootstrap and providers load cleanly
- **WHEN** the `app_boots` check runs
- **THEN** `status` SHALL be `"pass"` and `evidence` SHALL include the
  `Laravel Framework <version>` line as a `kind: "stdout"` excerpt

#### Scenario: App does not boot
- **GIVEN** a fixture repo whose `bootstrap/app.php` throws on load
- **WHEN** the `app_boots` check runs
- **THEN** `status` SHALL be `"fail"` and `evidence` SHALL include a
  `kind: "stderr"` excerpt

### Requirement: Migrations run check (`migrations_run`)
The runner SHALL create a throwaway SQLite database at
`<repoPath>/storage/runner-sqlite-<pid>.sqlite`, point Laravel at it via a
runtime config override (not by mutating the student's `.env`), run
`php artisan migrate --force`, then delete the SQLite file in a `finally`
block. The check:
- `pass` — `migrate` exits `0`.
- `fail` — `migrate` exits non-zero. `evidence` SHALL include the failing
  migration's file path and line where the exception originated when
  available.
- `skip` — `error_class: "env_missing_sqlite"` when the `pdo_sqlite`
  extension is not loaded; the file is NOT created.

#### Scenario: Migrations run cleanly
- **GIVEN** a fixture repo with a valid default migrations directory
- **WHEN** the `migrations_run` check runs
- **THEN** `status` SHALL be `"pass"`
- **AND** the SQLite file SHALL NOT remain in `<repoPath>/storage/` after the
  check returns

#### Scenario: A migration throws
- **GIVEN** a fixture repo whose second migration references a non-existent
  column
- **WHEN** the `migrations_run` check runs
- **THEN** `status` SHALL be `"fail"`
- **AND** `evidence` SHALL include the failing migration's relative `file`
  path and the `line` where the exception was thrown

#### Scenario: SQLite missing on host
- **GIVEN** a host where `pdo_sqlite` is not loaded
- **WHEN** the `migrations_run` check runs
- **THEN** `status` SHALL be `"skip"` and `error_class` SHALL be
  `"env_missing_sqlite"`
- **AND** no SQLite file SHALL be created in `<repoPath>/storage/`

### Requirement: Real README check (`readme_real`)
The runner SHALL verify that a `README` (any of `README`, `README.md`,
`README.txt`, case-insensitive) exists at the repo root, is non-empty, is at
least 200 bytes, and is NOT byte-equal to the bundled Laravel default stub
fixture at `tests/fixtures/laravel_readme_stub.md`.

The check:
- `pass` — all conditions hold.
- `fail` — any condition fails. `evidence` SHALL cite the inspected file path
  and the byte length when the failure is length or stub-match.
- `skip` — SHALL NOT happen.

#### Scenario: Real README present
- **GIVEN** a fixture repo with a 500-byte custom `README.md`
- **WHEN** the `readme_real` check runs
- **THEN** `status` SHALL be `"pass"`

#### Scenario: README is the Laravel default stub
- **GIVEN** a fixture repo whose `README.md` is byte-equal to the bundled stub
- **WHEN** the `readme_real` check runs
- **THEN** `status` SHALL be `"fail"`
- **AND** `evidence` SHALL include `file: "README.md"` and a `message`
  indicating the stub match

#### Scenario: No README at all
- **GIVEN** a fixture repo with no README-like file at the root
- **WHEN** the `readme_real` check runs
- **THEN** `status` SHALL be `"fail"` and `error_class` SHALL be
  `"readme_missing"`

### Requirement: `.env` not tracked check (`env_not_tracked`)
The runner SHALL run `git ls-files` inside `<repoPath>` and verify that no path
named `.env` appears in the tracked set. (A `.env.example` is allowed.)

The check:
- `pass` — no tracked `.env`.
- `fail` — a tracked `.env` exists. `evidence` SHALL cite `file: ".env"`.
- `skip` — `error_class: "not_a_git_repo"` when `git ls-files` exits non-zero
  because the path is not a git repository.

#### Scenario: `.env` not tracked
- **GIVEN** a fixture repo where `.env` is in `.gitignore` and not committed
- **WHEN** the `env_not_tracked` check runs
- **THEN** `status` SHALL be `"pass"`

#### Scenario: `.env` was committed
- **GIVEN** a fixture repo where `.env` is tracked by git
- **WHEN** the `env_not_tracked` check runs
- **THEN** `status` SHALL be `"fail"`
- **AND** `evidence` SHALL include `file: ".env"`

#### Scenario: Repo is not a git repo
- **GIVEN** a path with no `.git` directory
- **WHEN** the `env_not_tracked` check runs
- **THEN** `status` SHALL be `"skip"` and `error_class` SHALL be
  `"not_a_git_repo"`

### Requirement: Git history real check (`git_history_real`)
The runner SHALL count commits via `git rev-list --count HEAD` inside
`<repoPath>`. The check:
- `pass` — commit count is greater than `1`.
- `fail` — commit count is `1` or `0`.
- `skip` — `error_class: "not_a_git_repo"` when not a git repository.

The check SHALL NOT consider authors — only commit count. (R5: resist clever
checks; authorship is spoofable and not the runner's concern.)

#### Scenario: More than one commit
- **GIVEN** a fixture repo with 3 commits
- **WHEN** the `git_history_real` check runs
- **THEN** `status` SHALL be `"pass"`

#### Scenario: Single-commit repo
- **GIVEN** a fixture repo with exactly 1 commit
- **WHEN** the `git_history_real` check runs
- **THEN** `status` SHALL be `"fail"` and `error_class` SHALL be
  `"single_commit_history"`

#### Scenario: Not a git repo
- **GIVEN** a path with no `.git` directory
- **WHEN** the `git_history_real` check runs
- **THEN** `status` SHALL be `"skip"` and `error_class` SHALL be
  `"not_a_git_repo"`

